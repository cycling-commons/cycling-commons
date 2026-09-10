<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CatalogProvider;
use App\Catalog\ConfirmationStance;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Community\ItemConfirmationService;
use App\Contribution\CatalogContributionService;
use App\Coverage\CoverageRepository;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One-tap OSM confirm: materialize + receipt, not a live item.
 *
 * @see docs/specs/osm-data-architecture.md §6
 *
 * @api
 */
final class OsmConfirmController extends AbstractController
{
    /** Stance → registry fields. */
    private const array STANCE_FIELDS = [
        'potable' => ['potable' => 'Yes (public supply)'],
        'not_potable' => ['potable' => 'No / non-potable'],
        'exists' => [],
        'out_of_order' => ['condition' => 'Out of order'],
        'closed' => ['condition' => 'Closed'],
        'gone' => ['condition' => 'Not there anymore'],
    ];

    public function __construct(
        private readonly CatalogContributionService $contributions,
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ItemConfirmationService $confirmations,
        private readonly CatalogProvider $catalog,
    ) {
    }

    /**
     * Condition report on an already-ours place (ordinary improve, not a tally).
     *
     * @see docs/specs/moderation-and-contribution.md §10.4
     */
    #[Route('/items/{id}/condition', name: 'item_condition', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function condition(Request $request, int $id): JsonResponse
    {
        if (!$this->isCsrfTokenValid('item-confirm', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $stance = (string) $request->request->get('stance');
        if (!\in_array($stance, ['out_of_order', 'closed', 'gone'], true)) {
            return $this->json(['error' => 'invalid_stance'], 422);
        }
        $value = self::STANCE_FIELDS[$stance]['condition'];

        $item = $this->em->find(Item::class, $id);
        if (null === $item || !\in_array($item->getState(), ItemState::SERVED, true)) {
            return $this->json(['error' => 'unknown_item'], 404);
        }
        $type = null;
        foreach (ItemType::cases() as $case) {
            if ($case->letter() === $item->getLetter()) {
                $type = $case;
                break;
            }
        }
        if (null === $type || !$type->isConfirmable()) {
            return $this->json(['error' => 'not_confirmable'], 422);
        }
        if ('out_of_order' === $stance && !$type->canBreak()) {
            return $this->json(['error' => 'invalid_stance'], 422);
        }

        if ($value === ($item->getAttributes()['condition'] ?? null)) {
            return $this->json(['error' => 'already_recorded'], 409);
        }
        $pending = $this->db->fetchOne(
            "SELECT id FROM submission WHERE item_id = :id AND status = 'pending'
                 AND changes->'condition'->>'now' = :value LIMIT 1",
            ['id' => $id, 'value' => $value],
        );
        if (false !== $pending) {
            return $this->json(['error' => 'pending_review', 'submissionId' => (int) $pending], 409);
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $receipt = $this->contributions->submit('improve', [
                '_item_id' => $id,
                'details' => ['condition' => $value],
                '_title_fallback' => $this->translator->trans('item_type.'.$type->value.'.label'),
            ], $user);
        } catch (TooManyRequestsHttpException) {
            return $this->json(['error' => 'rate_limited'], 429);
        } catch (ValidationFailedException) {
            return $this->json(['error' => 'invalid'], 422);
        }

        return $this->json(['ok' => true, 'reference' => $receipt->reference, 'stance' => $stance]);
    }

    #[Route('/osm/confirm', name: 'osm_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function confirm(Request $request, CoverageRepository $coverage): JsonResponse
    {
        if (!$this->isCsrfTokenValid('item-confirm', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $ref = (string) $request->request->get('ref');
        if (1 !== preg_match('~^(node|way)/(\d{1,16})$~', $ref, $m)) {
            return $this->json(['error' => 'bad_ref'], 400);
        }
        $stance = (string) $request->request->get('stance');
        if (!\array_key_exists($stance, self::STANCE_FIELDS)) {
            return $this->json(['error' => 'invalid_stance'], 422);
        }

        $poi = $coverage->detail($m[1], (int) $m[2]);
        if (null === $poi) {
            return $this->json(['error' => 'unknown_ref'], 404);
        }
        $existing = $this->db->fetchAssociative(
            "SELECT id, state FROM item WHERE source_ref = :ref AND letter = :letter
                 AND state NOT IN ('rejected', 'retired') LIMIT 1",
            ['ref' => $ref, 'letter' => $poi['letter']],
        );
        if (false !== $existing) {
            return $this->json([
                'error' => ItemState::Submitted->value === $existing['state'] ? 'pending_review' : 'already_materialized',
                'itemId' => (int) $existing['id'],
            ], 409);
        }

        $type = null;
        foreach (ItemType::cases() as $case) {
            if ($case->letter() === $poi['letter']) {
                $type = $case;
                break;
            }
        }
        if (null === $type || !$type->isConfirmable()) {
            return $this->json(['error' => 'not_confirmable'], 422);
        }
        if ('out_of_order' === $stance && !$type->canBreak()) {
            return $this->json(['error' => 'invalid_stance'], 422);
        }

        $name = trim((string) ($poi['name'] ?? ''));
        if ('' === $name) {
            $name = $this->translator->trans('item_type.'.$type->value.'.label');
        }

        /** @var User $user */
        $user = $this->getUser();

        try {
            $receipt = $this->contributions->submit('add', [
                'type' => $type->value,
                'details' => [Item::NAME_FIELD => $name] + self::STANCE_FIELDS[$stance],
                'lat' => $poi['ll'][0],
                'lng' => $poi['ll'][1],
                'mode' => 'add',
                '_osm_ref' => $ref,
            ], $user);
        } catch (TooManyRequestsHttpException) {
            return $this->json(['error' => 'rate_limited'], 429);
        } catch (ValidationFailedException) {
            return $this->json(['error' => 'invalid'], 422);
        }

        // A curator's own tap does not wait for a curator (owner 2026-09-10;
        // moderation-and-contribution.md §1.6): the intake applied it, and
        // their answer is their word, the same drawer confirmation their click
        // on a served pin would write. The reply carries the served feature
        // so the map draws the pin at once.
        $applied = $receipt->applied;
        $verified = false;
        $feature = null;
        if ($applied && null !== $receipt->submissionId) {
            $submission = $this->em->find(Submission::class, $receipt->submissionId);
            $item = null !== $submission && null !== $submission->getItemId() ? $this->em->find(Item::class, $submission->getItemId()) : null;
            $answer = ConfirmationStance::tryFrom($stance);
            if (null !== $item && null !== $answer && \in_array($answer, ItemConfirmationService::offeredFor($item), true)) {
                $this->confirmations->record($item, $user, $answer);
                $this->em->flush();
                $verified = ItemState::Verified === $item->getState();
            }
            $feature = null !== $item ? $this->catalog->featureForItem((int) $item->getId()) : null;
        }

        return $this->json([
            'ok' => true,
            'reference' => $receipt->reference,
            'stance' => $stance,
            'applied' => $applied,
            'verified' => $verified,
            'item' => $feature,
        ]);
    }
}
