<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\BulkExport\BulkExportBuilder;
use App\BulkExport\BulkExportCatalog;
use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The bulk export's page: the newest snapshot, its files with sizes and
 * checksums, what it holds per source and licence, and what it leaves out.
 *
 * @see docs/specs/api-strategy.md §3.1
 *
 * @phpstan-import-type Manifest from BulkExportBuilder
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
final class BulkExportPageController extends AbstractController
{
    #[Route(LocalizedPath::DEVELOPERS_EXPORT, name: 'data_export')]
    public function __invoke(BulkExportCatalog $catalog): Response
    {
        return $this->render('pages/developers_export.html.twig', self::context($catalog->latest()));
    }

    /**
     * What the page template needs; also rendered, with a 404, by the
     * `latest` links while nothing is published.
     *
     * @param Manifest|null $manifest
     *
     * @return array<string, mixed>
     */
    public static function context(?array $manifest): array
    {
        return [
            'page_title' => 'data_export.meta_title',
            'page_description' => 'data_export.meta_description',
            'nav_active' => 'developers',
            'manifest' => $manifest,
            'keep' => BulkExportBuilder::KEEP,
            'attribution' => BulkExportBuilder::ATTRIBUTION,
        ];
    }
}
