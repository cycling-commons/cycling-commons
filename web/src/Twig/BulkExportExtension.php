<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\BulkExport\BulkExportCatalog;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `bulk_export_published()`: whether `/developers` may offer the bulk
 * export's download links (docs/specs/api-strategy.md §3.1).
 *
 * Until the first snapshot is published those links would end in a 404, so
 * the page says the first export is on its way instead. One cache read
 * ({@see BulkExportCatalog::latest()}), no storage call.
 *
 * @api
 */
final class BulkExportExtension extends AbstractExtension
{
    public function __construct(private readonly BulkExportCatalog $catalog)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('bulk_export_published', $this->published(...))];
    }

    public function published(): bool
    {
        return null !== $this->catalog->latest();
    }
}
