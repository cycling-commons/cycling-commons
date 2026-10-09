<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\ClosureExpiryService;
use App\Catalog\Entity\Item;
use App\Catalog\LocationMode;
use App\Catalog\PlaceKind;
use App\Catalog\SurfaceVocabulary;
use App\Contribution\CatalogContributionService;
use App\Entity\User;
use App\Scout\ScoutTag;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * One approved Scout tag → one submission. Ride file never uploaded.
 *
 * @see docs/specs/moderation-and-contribution.md (Scout intake)
 *
 * @api
 */
final class ScoutIntakeController extends AbstractController
{
    /** Keys that would mean a track was sent — refuse, do not ignore.
     *
     * @see docs/specs/moderation-and-contribution.md (Scout intake)
     */
    private const array TRACK_KEYS = ['track', 'points', 'trkpt', 'polyline', 'records', 'route', 'coordinates', 'gpx', 'fit'];

    public function __construct(
        private readonly CatalogContributionService $contributions,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/scout/tags', name: 'scout_tag_submit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function submit(Request $request): JsonResponse
    {
        // docs/specs/security-architecture.md §5.1 — stateless X-CC-Token.
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
                return new JsonResponse([
                    'error' => 'track_not_accepted',
                    'detail' => "The ride never leaves the browser. '{$forbidden}' may not be sent.",
                ], 422);
            }
        }

        $type = (string) ($payload['tag'] ?? '');
        $letter = (string) ($payload['letter'] ?? '');
        $autoFiledOther = 'other' === $type && '' === $letter;
        if ($autoFiledOther) {
            $letter = 'E';
        }
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

        // docs/specs/moderation-and-contribution.md (Scout intake) — A is segment-located; timings never leave the browser.
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
        $osmSurface = (string) ($payload['osmSurface'] ?? '');
        if ('A' === $letter && '' !== $osmSurface && !isset($details['surface'])) {
            $declarable = SurfaceVocabulary::fromOsmValue($osmSurface);
            if (null !== $declarable) {
                $details['surface'] = $declarable;
            }
        }

        // A sub-menu answer never follows a tag re-filed onto another letter: the field would not exist there.
        if ($letter === (ScoutTag::lettersFor($type, $detail)[0] ?? null)) {
            foreach (ScoutTag::fieldsFor($type, $detail) as $field => $value) {
                $details[$field] = $value;
            }
        }
        // A scenic or history place goes back to OSM as one tag, so it needs its kind (docs/specs/osm-data-architecture.md §5a).
        if (\in_array($letter, PlaceKind::LETTERS, true)
            && null === PlaceKind::label($letter, (string) ($details['type'] ?? ''))) {
            return new JsonResponse(['error' => 'kind_required'], 422);
        }
        if ($autoFiledOther && !isset($details['hazardType'])) {
            $details['hazardType'] = 'Other';
        }
        $details[Item::NAME_FIELD] = trim((string) ($details[Item::NAME_FIELD] ?? ''));
        if ('' === $details[Item::NAME_FIELD]) {
            return new JsonResponse(['error' => 'name_required'], 400);
        }

        // A date the rider can have been there, or none: a device clock with
        // no fix (the FIT epoch) or set ahead must not start or stop a
        // closure's clock (ClosureExpiryService). The tag itself still goes.
        $observed = \is_string($payload['observedAt'] ?? null)
            ? ClosureExpiryService::observedDate($payload['observedAt'], $this->clock->now())
            : null;
        $observedDate = $observed?->format('Y-m-d');

        /** @var User $user */
        $user = $this->getUser();

        try {
            $receipt = $this->contributions->submit('add', [
                'type' => $itemType->value,
                'details' => $details,
                'observedAt' => $observedDate,
                'osmSurface' => '' !== $osmSurface ? $osmSurface : null,
                'segment' => \is_string($rawSegment) ? $rawSegment : null,
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'mode' => 'add',
                'mediaIds' => $payload['mediaIds'] ?? null,
                'via' => 'scout',
                // The rider's raw pick: the Type above is a reading of it, the pick is the fact.
                'scoutPick' => ['tag' => $type, 'detail' => $detail],
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
