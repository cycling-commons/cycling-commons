<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\BulkExport\BulkExportBuilder;
use App\BulkExport\BulkExportCatalog;
use App\BulkExport\BulkExportStorage;
use App\Security\PseudonymousKey;
use League\Flysystem\FilesystemException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The bulk export's files (docs/specs/api-strategy.md §3.1), streamed from
 * storage so the bucket itself is never addressed by a browser.
 *
 * A snapshot never changes once published, so its files are marked immutable
 * for {@see SNAPSHOT_TTL} (a week, the build cadence), with the file's sha256
 * as the ETag and the build time as Last-Modified: a mirror that asks again
 * gets a 304 and no bytes. A week and not a year, because a snapshot taken
 * down has to leave the nginx cache too (docs/specs/operations.md §1,
 * "Taking a bulk export snapshot down"). The `latest` links redirect to the newest snapshot and are
 * cached for minutes; while nothing is published they answer 404 with the
 * export page, which says so. The paths carry no language: a file is the same
 * file in every one.
 *
 * A stamp is looked up only among the known snapshots
 * ({@see BulkExportCatalog::isKnown()}): any other one is a 404 that touches
 * neither storage nor a counter. Every request for a known snapshot, whatever
 * the file or method, then goes through the per-address limiter before
 * anything else is read.
 *
 * @api
 */
final class BulkExportDownloadController extends AbstractController
{
    private const string FILE = 'places\.geojson\.gz|routes\.geojson\.gz|manifest\.json';

    /** How long any cache may hold a snapshot's file: a week, the snapshot cadence. */
    private const int SNAPSHOT_TTL = 604800;

    /** How long a `latest` redirect may be held. */
    private const int LATEST = 300;

    public function __construct(
        private readonly BulkExportCatalog $catalog,
        private readonly BulkExportStorage $storage,
    ) {
    }

    #[Route('/data/export/latest/{file}', name: 'data_export_latest', requirements: ['file' => self::FILE], methods: ['GET', 'HEAD'])]
    public function latest(string $file): Response
    {
        $manifest = $this->catalog->latest();
        if (null === $manifest) {
            // Not an error: the export page, saying the first snapshot is on
            // its way. Not marked shareable: the page's navigation can name a
            // signed-in rider, and only PublicPageCacheSubscriber may decide
            // that a page is the same for everyone.
            return $this->render('pages/developers_export.html.twig', BulkExportPageController::context(null), new Response('', Response::HTTP_NOT_FOUND));
        }
        $response = new RedirectResponse($this->generateUrl('data_export_file', ['stamp' => $manifest['snapshot'], 'file' => $file]), Response::HTTP_FOUND);
        $response->setPublic();
        $response->setMaxAge(self::LATEST);
        $response->setSharedMaxAge(self::LATEST);

        return $response;
    }

    #[Route('/data/export/{stamp}/{file}', name: 'data_export_file', requirements: ['stamp' => BulkExportStorage::STAMP_PATTERN, 'file' => self::FILE], methods: ['GET', 'HEAD'])]
    public function download(Request $request, string $stamp, string $file, RateLimiterFactoryInterface $bulkExportDownloadLimiter, #[Autowire('%kernel.secret%')] string $secret): Response
    {
        if (!$this->catalog->isKnown($stamp)) {
            throw new NotFoundHttpException('No such snapshot.');
        }

        // A coded address, like the other anonymous counters (privacy.collect_auto_ratelimit).
        $limit = $bulkExportDownloadLimiter->create(PseudonymousKey::limiter('bulk_export_download', $request->getClientIp() ?? 'unknown', $secret))->consume();
        if (!$limit->isAccepted()) {
            $refused = new Response("Too many downloads from this address. Please try again later.\n", Response::HTTP_TOO_MANY_REQUESTS, ['Content-Type' => 'text/plain; charset=UTF-8']);
            $refused->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $refused;
        }

        $manifest = $this->catalog->snapshot($stamp);
        if (null === $manifest) {
            throw new NotFoundHttpException('No such snapshot.');
        }
        $generatedAt = new \DateTimeImmutable($manifest['generated_at']);

        if (BulkExportBuilder::MANIFEST === $file) {
            // The manifest is small and is what a mirror reads first: served
            // from the cached copy, byte for byte what the builder wrote.
            $json = json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
            $response = new Response('', Response::HTTP_OK, ['Content-Type' => 'application/json']);
            $this->describe($response, hash('sha256', $json), $generatedAt);
            if ($response->isNotModified($request)) {
                return $response;
            }
            $response->setContent($json);

            return $response;
        }

        $entry = null;
        foreach ($manifest['files'] as $candidate) {
            if ($candidate['name'] === $file) {
                $entry = $candidate;
            }
        }
        if (null === $entry) {
            throw new NotFoundHttpException('No such file in this snapshot.');
        }

        $response = new Response('', Response::HTTP_OK, ['Content-Type' => $entry['content_type']]);
        $this->describe($response, $entry['sha256'], $generatedAt);
        if ($response->isNotModified($request)) {
            return $response;
        }
        $response->headers->set('Content-Length', (string) $entry['bytes']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'cycling-commons-'.str_replace('.geojson.gz', '', $file).'-'.$stamp.'.geojson.gz',
        ));
        if ($request->isMethod('HEAD')) {
            return $response;
        }

        try {
            $stream = $this->storage->readStream($stamp.'/'.$file);
        } catch (FilesystemException) {
            // The manifest outlived its files: retention deleted the snapshot.
            throw new NotFoundHttpException('This snapshot is no longer kept.');
        }

        $streamed = new StreamedResponse(static function () use ($stream): void {
            $out = fopen('php://output', 'wb');
            if (false !== $out) {
                stream_copy_to_stream($stream, $out);
                fclose($out);
            }
            fclose($stream);
        }, Response::HTTP_OK, $response->headers->all());

        return $streamed;
    }

    /** The validators and cache policy of a snapshot's file. */
    private function describe(Response $response, string $sha256, \DateTimeImmutable $generatedAt): void
    {
        $response->setEtag($sha256);
        $response->setLastModified($generatedAt);
        $response->setPublic();
        $response->setMaxAge(self::SNAPSHOT_TTL);
        $response->setSharedMaxAge(self::SNAPSHOT_TTL);
        $response->setImmutable();
    }
}
