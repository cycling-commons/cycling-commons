<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\Entity\Item;
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
        if (!\in_array($type, ScoutTag::TYPES, true) || !ScoutTag::allows($type, $letter)) {
            return new JsonResponse(['error' => 'bad_tag'], 400);
        }
        $itemType = ScoutTag::itemTypeFor($letter);
        if (null === $itemType) {
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
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'mode' => 'add',
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
