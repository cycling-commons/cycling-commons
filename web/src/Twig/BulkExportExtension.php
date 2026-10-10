<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Controller\BulkExportLatestController;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The bulk export's Twig functions (docs/specs/api-strategy.md §3.1).
 *
 * `bulk_export_file(stamp, file)`: the path of a snapshot's file. nginx
 * serves it from the bucket, so it has no route to generate it from
 * ({@see BulkExportLatestController::fileUrl()}).
 *
 * @api
 */
final class BulkExportExtension extends AbstractExtension
{
    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('bulk_export_file', BulkExportLatestController::fileUrl(...)),
        ];
    }
}
