<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\BulkExport;

/**
 * No bucket is configured for the bulk export (`DATA_EXPORT_BUCKET` is empty).
 *
 * @api
 */
final class BulkExportUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The bulk export has no storage: set DATA_EXPORT_BUCKET to the bucket it lives in.');
    }
}
