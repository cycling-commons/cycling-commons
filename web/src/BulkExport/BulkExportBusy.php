<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\BulkExport;

/**
 * Another bulk export build holds the lock ({@see BulkExportBuilder::LOCK}).
 *
 * @api
 */
final class BulkExportBusy extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A bulk export build is already running. Nothing was written; run it again once that one is done.');
    }
}
