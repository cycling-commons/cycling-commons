<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\BulkExport\BulkExportCatalog;
use App\BulkExport\BulkExportStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The bulk export's `latest` links (docs/specs/api-strategy.md §3.1):
 * `/developers/export/latest/places`, `/routes` and `/manifest`, each a 302 to
 * the newest snapshot's file, held for {@see LATEST} seconds. They are the
 * addresses a script keeps; the dated file they lead to names its build time
 * in its path and in its `generated_at`; its download name stays the same.
 *
 * The snapshot files themselves, `/data/export/<stamp>/<file>`, have no route
 * here: nginx on the web frontends answers that path from the bucket's
 * `exports/` folder, with the headers stored on each object, and counts the
 * downloads per address. {@see fileUrl()} builds that path for the page and
 * for this redirect. While nothing is published the `latest` links answer
 * 404 with the export page, which says so. The paths carry no language: a
 * file is the same file in every one.
 *
 * @api
 */
final class BulkExportLatestController extends AbstractController
{
    /** The files of a snapshot, as nginx serves them. */
    public const string FILE = 'places\.geojson\.gz|routes\.geojson\.gz|manifest\.json';

    /** How long a `latest` redirect may be held. */
    private const int LATEST = 300;

    /** A latest link's name => the snapshot file it leads to. */
    public const array LATEST_FILES = ['places' => 'places.geojson.gz', 'routes' => 'routes.geojson.gz', 'manifest' => 'manifest.json'];

    private const string PREFIX = '/data/export/';

    public function __construct(private readonly BulkExportCatalog $catalog)
    {
    }

    /** The public path of a snapshot's file, served by nginx. */
    public static function fileUrl(string $stamp, string $file): string
    {
        if (1 !== preg_match('/^'.BulkExportStorage::STAMP_PATTERN.'$/D', $stamp) || 1 !== preg_match('/^(?:'.self::FILE.')$/D', $file)) {
            throw new \InvalidArgumentException(sprintf('No export file "%s/%s".', $stamp, $file));
        }

        return self::PREFIX.$stamp.'/'.$file;
    }

    #[Route('/developers/export/latest/{name}', name: 'data_export_latest', requirements: ['name' => 'places|routes|manifest'], methods: ['GET', 'HEAD'])]
    public function latest(string $name): Response
    {
        $file = self::LATEST_FILES[$name];
        $manifest = $this->catalog->latest();
        if (null === $manifest) {
            // Not an error: the export page, saying the first snapshot is on
            // its way. Not marked shareable: the page's navigation can name a
            // signed-in rider, and only PublicPageCacheSubscriber may decide
            // that a page is the same for everyone.
            return $this->render('pages/developers_export.html.twig', BulkExportPageController::context(null), new Response('', Response::HTTP_NOT_FOUND));
        }
        $response = new RedirectResponse(self::fileUrl($manifest['snapshot'], $file), Response::HTTP_FOUND);
        $response->setPublic();
        $response->setMaxAge(self::LATEST);
        $response->setSharedMaxAge(self::LATEST);

        return $response;
    }
}
