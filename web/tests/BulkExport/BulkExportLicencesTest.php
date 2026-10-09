<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\BulkExport;

use App\BulkExport\BulkExportLicences;
use PHPUnit\Framework\TestCase;

/**
 * Which upstream licences may travel in the ODbL export
 * (docs/specs/data-source-register.md §1): the same test that decides whether
 * a source may enter the dataset at all, failing closed on anything unknown.
 */
final class BulkExportLicencesTest extends TestCase
{
    public function testOpenAndAttributionOnlyLicencesTravel(): void
    {
        foreach (['odbl', 'ODbL-1.0', 'cc0-1.0', 'pddl-1.0', 'pdm-1.0', 'public-domain', 'cc-by-4.0', ' CC-BY-4.0 '] as $code) {
            self::assertTrue(BulkExportLicences::allows($code), $code);
        }
    }

    public function testShareAlikeNonCommercialPerPhotoAndUnknownLicencesStayOut(): void
    {
        foreach (['cc-by-sa-4.0', 'cc-by-nc-4.0', 'cc-by-nd-4.0', 'per-photo', 'copernicus', '', 'proprietary', 'cc-by'] as $code) {
            self::assertFalse(BulkExportLicences::allows($code), "'{$code}' must not travel in the export");
        }
    }
}
