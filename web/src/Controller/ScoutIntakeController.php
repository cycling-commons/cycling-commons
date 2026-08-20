<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\Item;
use App\Catalog\LocationMode;
use App\Catalog\SurfaceVocabulary;
use App\Contribution\CatalogContributionService;
use App\Entity\User;
use App\Scout\ScoutTag;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Scout intake: one approved tag becomes one ordinary submission.
 *
 * The ride itself never arrives here. It is decoded in the rider's browser and
 * what this endpoint accepts is a single tag they have looked at and approved —
 * a point, a letter, the fields that letter's form defines, and when it was
 * observed. Nothing else, and *nothing else* is enforced (§ the track guard
 * below) rather than assumed.
 *
 * One tag per request, on purpose. A ride is thirty tags and thirty separate
 * decisions by the rider; batching them into one call would make a partial
 * failure unreportable ("nine of thirty went in — which nine?") and would put a
 * bulk-write verb in a codebase whose whole contribution story is one place at
 * a time.
 *
 * @api Instantiated by Symfony's router; called by assets/map/scout-review.js.
 */
final class ScoutIntakeController extends AbstractController
{
    /**
     * Payload keys that would mean a TRACK had been sent.
     *
     * The promise on `/scout` and `/privacy` is that a route is read in the
     * rider's own browser and never uploaded. A promise a future client change
     * can quietly break is not a promise, so the endpoint REFUSES a payload
     * carrying any of these rather than ignoring the extra field — ignoring is
     * how a trace starts arriving and nobody notices for a year.
     *
     * `segment` (plan task 6) is NOT a breach of that promise and is
     * deliberately absent from this list: it is the rider-approved excerpt
     * between a surface stretch's two taps — the same line they would draw in
     * the map wizard — coordinates only, no timestamps, accepted only for the
     * one segment-located letter and only when they press send on that card.
     */
    private const array TRACK_KEYS = ['track', 'points', 'trkpt', 'polyline', 'records', 'route', 'coordinates', 'gpx', 'fit'];

    public function __construct(
        private readonly CatalogContributionService $contributions,
    ) {
    }

    #[Route('/scout/tags', name: 'scout_tag_submit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function submit(Request $request): JsonResponse
    {
        // Stateless header token (review 2026-08-16 info note): this POST used
        // to lean on SameSite=lax alone, which made it the one JSON intake
        // without a token — inconsistency reads as an oversight to the next
        // reader. Same X-CC-Token convention as ride-check/elevation; the map
        // template mints CC_SCOUT_TOKEN.
        if (!$this->isCsrfTokenValid('scout-tags', (string) $request->headers->get('X-CC-Token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'bad_request'], 400);
        }

        foreach (self::TRACK_KEYS as $forbidden) {
            if (\array_key_exists($forbidden, $payload)) {
                // 422, not 400: the request is well-formed and deliberately
                // refused. The message is for the developer who added the
                // field, since no rider can ever see it.
                return new JsonResponse([
                    'error' => 'track_not_accepted',
                    'detail' => "The ride never leaves the browser. '{$forbidden}' may not be sent.",
                ], 422);
            }
        }

        $type = (string) ($payload['tag'] ?? '');
        $letter = (string) ($payload['letter'] ?? '');
        /* 'Other' carries no category from the device and, since 2026-08-18
           (owner), no category question in review either: it is a free-text
           observation. It auto-files as an F notice — "a rider noted
           something here", hazardType Other — and the curator's read of the
           description is the filing decision. One way to moderate holds: an
           ordinary submission, no new desk mechanics. (A bare surface tap
           never arrives at all: the review hides it, counted in the panel.) */
        $autoFiledOther = 'other' === $type && '' === $letter;
        if ($autoFiledOther) {
            $letter = 'F';
        }
        // The device's sub-menu value — which POTHOLES, which CLOSED FOR, which
        // SCENERY. It decides both what the tag may become and what it already
        // answers, so it travels with the tag rather than being re-asked.
        $detail = isset($payload['detail']) && is_numeric($payload['detail'])
            ? (int) $payload['detail']
            : null;
        if (!\in_array($type, ScoutTag::TYPES, true) || !ScoutTag::allows($type, $letter, $detail)) {
            return new JsonResponse(['error' => 'bad_tag'], 400);
        }
        $itemType = ScoutTag::itemTypeFor($letter);
        if (null === $itemType) {
            return new JsonResponse(['error' => 'bad_tag'], 400);
        }

        /* A · road surface is SEGMENT-located (plan task 6, built 2026-08-18):
           it must arrive WITH the rider-approved excerpt between the two taps
           — {a, b, line} in [lng, lat] pairs, the same shape the map wizard
           sends, fully re-validated by CatalogContributionService::
           decodeSegment (point cap, endpoint drift). Coordinates only, no
           timestamps: the excerpt is road data the rider chose to publish;
           the timings stay movement data and never leave the browser. A point
           letter carrying a segment is refused the other way around — a point
           has no business shipping a line. */
        $rawSegment = $payload['segment'] ?? null;
        if (LocationMode::Segment === $itemType->locationMode()) {
            if (!\is_string($rawSegment) || '' === trim($rawSegment)) {
                return new JsonResponse([
                    'error' => 'segment_required',
                    'detail' => 'A surface stretch needs its start/END pair and the ridden line between them.',
                ], 422);
            }
        } elseif (null !== $rawSegment) {
            return new JsonResponse(['error' => 'bad_tag'], 400);
        }

        $lat = $payload['lat'] ?? null;
        $lng = $payload['lng'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lng)
            || abs((float) $lat) > 90.0 || abs((float) $lng) > 180.0) {
            return new JsonResponse(['error' => 'bad_location'], 400);
        }

        /** @var array<string, mixed> $details */
        $details = \is_array($payload['details'] ?? null) ? $payload['details'] : [];
        /* A surface tag carries the value the rider chose ON THE DEVICE, in
           OSM's vocabulary. Translating it here saves them picking the same
           thing twice; a value we do not recognise is dropped rather than
           guessed, and then the form asks. */
        $osmSurface = (string) ($payload['osmSurface'] ?? '');
        if ('A' === $letter && '' !== $osmSurface && !isset($details['surface'])) {
            // Only when the rider left the dropdown alone: an explicit choice
            // in details.surface outranks the device's recording.
            $declarable = SurfaceVocabulary::fromOsmValue($osmSurface);
            if (null !== $declarable) {
                $details['surface'] = $declarable;
            }
        }

        /* What the sub-menu already said: a hazard's kind, a closure's duration.
           Applied only to the letter it belongs to — a rider who re-filed a
           notice as a scenic view must not carry `hazardType` onto an I item,
           where the field does not exist and the submission would be refused
           for an answer they never gave. */
        foreach (ScoutTag::fieldsFor($type, $detail) as $field => $value) {
            if ('F' === $letter) {
                $details[$field] = $value;
            }
        }
        if ($autoFiledOther && !isset($details['hazardType'])) {
            // The honest F answer for "something a rider noted": Other.
            $details['hazardType'] = 'Other';
        }
        $details[Item::NAME_FIELD] = trim((string) ($details[Item::NAME_FIELD] ?? ''));
        if ('' === $details[Item::NAME_FIELD]) {
            return new JsonResponse(['error' => 'name_required'], 400);
        }

        // When the tag was observed, straight from the ride file. This is the
        // half of a Scout tag a form can never supply: "a rider found this
        // flowing on 12 January" is evidence against a declared seasonality,
        // where a dropdown is only a claim (plan §4).
        $observed = (string) ($payload['observedAt'] ?? '');
        $observedDate = ('' !== $observed && false !== strtotime($observed))
            ? gmdate('Y-m-d', (int) strtotime($observed))
            : null;

        /** @var User $user */
        $user = $this->getUser();

        try {
            $receipt = $this->contributions->submit('add', [
                'type' => $itemType->value,
                'details' => $details,
                /* The observation date rides in the raw payload, which the
                   submission stores verbatim for the moderator — NOT in
                   `extras`, which is validated against the letter's registry
                   fields and would reject an attribute no form defines. Making
                   it a real attribute is task 6a of the Scout plan and a
                   registry change of its own; recording it now means the fact
                   is not lost in the meantime. */
                'observedAt' => $observedDate,
                /* The raw OSM surface value the device recorded, kept verbatim
                   in the submission payload beside the declarable label: this
                   data is meant to be fit to hand back to OSM one day (owner,
                   2026-08-18), and `surface=gravel` is the OSM-side fact while
                   'Gravel' is only our rendering of it. */
                'osmSurface' => '' !== $osmSurface ? $osmSurface : null,
                // The rider-approved excerpt; decodeSegment() re-validates it.
                'segment' => \is_string($rawSegment) ? $rawSegment : null,
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'mode' => 'add',
                /* A photo taken at the spot. Claimed by MediaClaimService in
                   the same transaction as the facts, exactly as the wizard's
                   photos are — a rejected id rolls the whole submission back
                   rather than leaving a half-attached contribution. Passed
                   through verbatim: this endpoint has no business re-deciding
                   what that service already validates. */
                'mediaIds' => $payload['mediaIds'] ?? null,
                // Provenance says HOW it was submitted, never that anything was
                // verified: the server did not see the ride and cannot check a
                // thing about it (plan §3).
                'via' => 'scout',
            ], $user);
        } catch (TooManyRequestsHttpException) {
            return new JsonResponse(['error' => 'rate_limited'], 429);
        } catch (ValidationFailedException $e) {
            $messages = [];
            foreach ($e->getViolations() as $violation) {
                $messages[] = (string) $violation->getMessage();
            }

            return new JsonResponse(['error' => 'invalid', 'messages' => $messages], 422);
        }

        return new JsonResponse(['ok' => true, 'ref' => $receipt->reference]);
    }
}
