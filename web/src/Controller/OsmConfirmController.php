<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\Item;
use App\Catalog\ItemState;
use App\Catalog\ItemType;
use App\Contribution\CatalogContributionService;
use App\Coverage\CoverageRepository;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One tap on an OSM place: it becomes ours, carrying the rider's answer.
 *
 * A confirmation is recorded against an item, and an OSM point is not one — so
 * a rider standing at a tap that OpenStreetMap knows about could say nothing at
 * all, and the only route in was the improve wizard: a form, for an answer that
 * is one word (owner-reported 2026-08-12).
 *
 * This endpoint IS that form, filled in from what we already know and posted for
 * them. It is materialize-on-edit (`osm-data-architecture.md` §6) with the
 * typing removed — the same submission, the same queue, the same moderators.
 * Nothing here approves anything: **one way to moderate** still binds, and what
 * the rider gets back is a receipt, not a live item.
 *
 * @api Instantiated by Symfony's router; called by assets/map/drawer.js.
 */
final class OsmConfirmController extends AbstractController
{
    /**
     * The answer each stance writes into the letter's own form.
     *
     * Only what the registry offers — this is the same vocabulary the wizard
     * would have shown, and a value it does not know would be refused there
     * too, one step later and less clearly.
     */
    private const array STANCE_FIELDS = [
        'potable' => ['potable' => 'Yes (public supply)'],
        'not_potable' => ['potable' => 'No / non-potable'],
        'exists' => [],
    ];

    public function __construct(
        private readonly CatalogContributionService $contributions,
        private readonly Connection $db,
        private readonly TranslatorInterface $translator,
    ) {
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

        // The POI as the coverage index holds it: the same lookup the wizard
        // does, so a ref we do not serve is refused here exactly as there.
        $poi = $coverage->detail($m[1], (int) $m[2]);
        if (null === $poi) {
            return $this->json(['error' => 'unknown_ref'], 404);
        }
        /* Is this place already ours? Not only "is it on the map" — a place
           awaiting review is ours too, and it is invisible from the drawer,
           which is exactly how the same tap from three riders would become
           three identical rows in the queue. One item per OSM ref: after the
           first tap, the rest are answered with what already happened. */
        $existing = $this->db->fetchAssociative(
            "SELECT id, state FROM item WHERE source_ref = :ref AND letter = :letter AND state <> 'rejected' LIMIT 1",
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

        /* A name is required, and most OSM taps have none. The layer's own
           label is what the drawer already calls it — "Drinking water" — so the
           rider sees the same words on the map, in the queue and on the item.
           Inventing "Unnamed place" would be worse: a curator reads the title
           first. */
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

        return $this->json([
            'ok' => true,
            'reference' => $receipt->reference,
            'stance' => $stance,
        ]);
    }
}
