<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Controller\BulkExportLatestController;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `bulk_export_published()`: whether `/developers` may offer the bulk
 * export's download links (docs/specs/api-strategy.md §3.1).
 *
 * `bulk_export_file(stamp, file)`: the path of a snapshot's file. nginx
 * serves it from the bucket, so it has no route to generate it from
 * ({@see BulkExportLatestController::fileUrl()}).
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
