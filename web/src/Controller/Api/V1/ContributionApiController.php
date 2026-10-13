<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Api\Partner\ApiApp;
use App\Api\Partner\ApiApps;
use App\Api\V1\ApiResponder;
use App\Api\V1\ContributionRequest;
use App\Api\V1\PublicFields;
use App\BulkExport\BulkExportBuilder;
use App\Contribution\ApiAuthor;
use App\Contribution\CatalogContributionService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The write path: a registered partner app sends a contribution, which
 * enters the same review queue as the site's own form, and later asks how
 * it went. Server to server: the key never belongs in a browser, so these
 * routes answer no cross-origin preflight (PublicApiCorsSubscriber allows
 * GET only).
 *
 * @see docs/specs/public-api.md §8
 *
 * @api
 */
final class ContributionApiController extends AbstractController
{
    /** The largest body a contribution needs; a note is 2000 characters. */
    private const int MAX_BODY_BYTES = 32768;

    public function __construct(
        private readonly ApiResponder $api,
        private readonly ApiApps $apps,
    ) {
    }

    #[Route('/v1/contributions', name: 'api_v1_contributions', methods: ['POST'])]
    public function create(
        Request $request,
        CatalogContributionService $contributions,
        PublicFields $fields,
        Connection $db,
        RateLimiterFactoryInterface $apiAppWriteLimiter,
        TranslatorInterface $translator,
    ): Response {
        $app = $this->app($request);
        if (!$app instanceof ApiApp) {
            return $app;
        }
        $limit = $apiAppWriteLimiter->create('app-'.$app->id)->consume();
        if (!$limit->isAccepted()) {
            $response = $this->api->error('rate_limited', 'this app has sent its hourly share of contributions; try again later', 429);
            $response->headers->set('Retry-After', (string) max(0, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        $raw = $request->getContent();
        if (\strlen($raw) > self::MAX_BODY_BYTES) {
            return $this->api->error('invalid_body', 'the body may be at most 32 KB', 413);
        }
        $body = json_decode($raw, true);
        if (!\is_array($body) || array_is_list($body)) {
            return $this->api->error('invalid_body', 'the body must be a JSON object');
        }

        // The item an edit names: one the API serves (a consumer cannot edit
        // what it cannot read), or the author's own new place still waiting
        // for a curator, so the app can revise it or answer a question on it,
        // as a rider revises theirs on the site.
        $editingLetter = null;
        $author = \is_array($body['author'] ?? null) ? ($body['author']['ref'] ?? null) : null;
        if (\is_int($body['item_id'] ?? null)) {
            $letter = $db->fetchOne(
                'SELECT i.letter FROM item i
                  WHERE i.id = :id AND ('.BulkExportBuilder::servedItemSql('i')."
                        OR EXISTS (SELECT 1 FROM submission s
                                    WHERE s.item_id = i.id AND s.type = 'new' AND s.status IN ('pending', 'needs_info')
                                      AND s.api_app_id = :app AND s.external_author_ref = :ref))",
                ['id' => $body['item_id'], 'app' => $app->id, 'ref' => \is_string($author) ? $author : ''],
            );
            $editingLetter = \is_string($letter) ? $letter : null;
        }

        $parsed = ContributionRequest::parse($body, $fields, $editingLetter);
        if (\is_array($parsed)) {
            return $this->api->error($parsed['error'], $parsed['message']);
        }

        $author = new ApiAuthor($app->id, $app->name, $parsed->authorRef, $parsed->authorName);
        try {
            $receipt = $contributions->submitFromApp($parsed->kind, $parsed->payload, $author);
        } catch (TooManyRequestsHttpException) {
            // The author's hourly share, or the router budget a scenic view's
            // distance check spends; both refill within the hour.
            $response = $this->api->error('rate_limited', 'this author has reached an hourly limit; try again later', 429);
            $response->headers->set('Retry-After', '3600');

            return $response;
        } catch (ValidationFailedException $e) {
            $violation = $e->getViolations()->get(0);

            return new JsonResponse([
                'error' => 'invalid_contribution',
                'message' => $translator->trans((string) $violation->getMessage(), [], null, 'en'),
                'field' => '' !== $violation->getPropertyPath() ? $violation->getPropertyPath() : null,
            ], 400);
        } catch (\InvalidArgumentException $e) {
            return $this->api->error('invalid_contribution', $e->getMessage());
        }

        $submissionId = (int) $receipt->submissionId;

        return (new JsonResponse([
            'submission_id' => $submissionId,
            'status' => 'queued_for_review',
            'kind' => 'add' === $parsed->kind ? 'new' : 'edit',
            'letter' => $parsed->letter,
            'item_id' => $this->itemOf($db, $submissionId),
            'status_url' => '/v1/contributions/'.$submissionId,
        ], 202))->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Where one of this app's contributions stands. Another app's
     * submission, or a rider's, is a 404 like one that never existed.
     */
    #[Route('/v1/contributions/{id}', name: 'api_v1_contribution', requirements: ['id' => '\d{1,18}'], methods: ['GET'])]
    public function status(Request $request, int $id, Connection $db): Response
    {
        $app = $this->app($request);
        if (!$app instanceof ApiApp) {
            return $app;
        }

        /** @var array{id: int|string, type: string, letter: string, item_id: int|string|null, status: string, decided_at: string|null, decision_note: string|null}|false $row */
        $row = $db->fetchAssociative(
            'SELECT id, type, letter, item_id, status, decided_at, decision_note FROM submission WHERE id = :id AND api_app_id = :app',
            ['id' => $id, 'app' => $app->id],
        );
        if (false === $row) {
            return $this->api->notFound(\sprintf('no contribution %d from this app', $id));
        }

        $response = new JsonResponse([
            'submission_id' => (int) $row['id'],
            'kind' => 'new' === $row['type'] ? 'new' : 'edit',
            'letter' => $row['letter'],
            'item_id' => null !== $row['item_id'] ? (int) $row['item_id'] : null,
            // pending, needs_info, approved, rejected, withdrawn or trashed.
            'status' => $row['status'],
            'decided_at' => null !== $row['decided_at'] ? (new \DateTimeImmutable($row['decided_at']))->format(\DATE_ATOM) : null,
            // What the curator wrote to the author, for the app to pass on.
            'decision_note' => $row['decision_note'],
        ]);
        $response->setPrivate();

        return $response;
    }

    /**
     * The app relays that one of its users deleted their account
     * (public-api.md §8): the name they shared is blanked on every
     * contribution they sent, so wherever it was shown it now reads
     * anonymous. The reference stays, because it identifies nobody.
     */
    #[Route('/v1/authors/{ref}', name: 'api_v1_author_forget', requirements: ['ref' => '[A-Za-z0-9._:-]{1,64}'], methods: ['DELETE'])]
    public function forgetAuthor(Request $request, string $ref, Connection $db): Response
    {
        $app = $this->app($request);
        if (!$app instanceof ApiApp) {
            return $app;
        }

        $db->executeStatement(
            'UPDATE submission SET external_author_name = NULL WHERE api_app_id = :app AND external_author_ref = :ref AND external_author_name IS NOT NULL',
            ['app' => $app->id, 'ref' => $ref],
        );

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * The calling app, or the 429/401/403 that says why not. The per-address
     * read limit comes first, so a stream of made-up keys costs no lookups.
     */
    private function app(Request $request): ApiApp|JsonResponse
    {
        if (null !== ($limited = $this->api->rateLimited($request))) {
            return $limited;
        }
        $app = $this->apps->fromRequest($request);
        if (null === $app) {
            $response = $this->api->error('unauthorized', 'send a partner app key as Authorization: Bearer <key>; writes are for registered partner apps', 401);
            $response->headers->set('WWW-Authenticate', 'Bearer realm="Cycling Commons API"');

            return $response;
        }
        if (!$app->may(ApiApps::SCOPE_WRITE)) {
            return $this->api->error('forbidden', 'this key may not send contributions', 403);
        }

        return $app;
    }

    private function itemOf(Connection $db, int $submissionId): ?int
    {
        $itemId = $db->fetchOne('SELECT item_id FROM submission WHERE id = :id', ['id' => $submissionId]);

        return is_numeric($itemId) ? (int) $itemId : null;
    }
}
