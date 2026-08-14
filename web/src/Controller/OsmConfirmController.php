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
        /* The three ways a mapped place stops being true (owner 2026-08-12).
           They write the SAME field the edit form offers, so a rider who wants
           to add why can open the form and find their own answer already
           chosen rather than a second, contradictory record of it. */
        'out_of_order' => ['condition' => 'Out of order'],
        'closed' => ['condition' => 'Closed'],
        'gone' => ['condition' => 'Not there anymore'],
    ];

    public function __construct(
        private readonly CatalogContributionService $contributions,
        private readonly Connection $db,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The same three answers, for a place that is already ours.
     *
     * Materializing an OSM tap does not freeze it: a water point added as
     * existing and potable can be removed, shut off or break next season
     * (owner 2026-08-12). Before this, our own items offered only the
     * letter's confirmation - potable / still here - so the moment a place
     * became ours, the ways of saying it had STOPPED being true disappeared.
     *
     * It is an edit, not a confirmation: a claim about the place rather than a
     * vote on it, so it travels as an ordinary improve submission through the
     * same queue as any other edit. No new moderation mechanic, and the same
     * `condition` vocabulary as the OSM arm and the edit form.
     */
    #[Route('/items/{id}/condition', name: 'item_condition', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function condition(Request $request, int $id): JsonResponse
    {
        if (!$this->isCsrfTokenValid('item-confirm', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $stance = (string) $request->request->get('stance');
        // 'As mapped' is not offered here: saying a place is fine IS the
        // letter's confirmation, and it already has a button.
        if (!\in_array($stance, ['out_of_order', 'closed', 'gone'], true)) {
            return $this->json(['error' => 'invalid_stance'], 422);
        }
        $value = self::STANCE_FIELDS[$stance]['condition'];

        $item = $this->em->find(Item::class, $id);
        if (null === $item || !\in_array($item->getState(), ItemState::SERVED, true)) {
            return $this->json(['error' => 'unknown_item'], 404);
        }
        // Same letter→type walk the OSM arm does; ItemType is keyed by slug,
        // and the item carries the letter.
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
        // 'Out of order' needs working parts, and a viewpoint has none
        // (ItemType::canBreak). The map has never drawn this button for such a
        // type, so refusing it here costs no real caller anything and stops the
        // stored vocabulary from drifting past what the form can express.
        if ('out_of_order' === $stance && !$type->canBreak()) {
            return $this->json(['error' => 'invalid_stance'], 422);
        }

        /* Already said, by this rider or by anyone: the map is not a tally of
           how many people watched the same tap break. A second report of the
           same thing is answered with what already happened, exactly as the
           OSM arm answers a second tap. */
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
                // Most materialized POIs are nameless; the queue still needs a
                // heading, and the layer's own words are the ones the rider
                // just read in the drawer.
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
            "SELECT id, state FROM item WHERE source_ref = :ref AND letter = :letter
                 AND state NOT IN ('rejected', 'retired') LIMIT 1",
            ['ref' => $ref, 'letter' => $poi['letter']],
        );
        // A place a curator turned down may be proposed again - the row is
        // revived by the intake rather than twinned (CatalogContributionService).
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
        // 'Out of order' needs working parts, and a viewpoint has none
        // (ItemType::canBreak). The map has never drawn this button for such a
        // type, so refusing it here costs no real caller anything and stops the
        // stored vocabulary from drifting past what the form can express.
        if ('out_of_order' === $stance && !$type->canBreak()) {
            return $this->json(['error' => 'invalid_stance'], 422);
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
