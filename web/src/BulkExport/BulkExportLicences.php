<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\BulkExport;

/**
 * Which upstream licences may travel in the bulk export, which is published
 * under the ODbL 1.0 with the DbCL 1.0 for its contents.
 *
 * The rule is the licence test of docs/specs/data-source-register.md §1: the
 * ODbL itself, public-domain dedications and marks, and attribution-only
 * licences (CC BY 4.0 and the national open-government licences that ask for
 * attribution and nothing else). Share-alike licences other than the ODbL
 * (CC BY-SA), non-commercial or no-derivatives terms, per-photo licences and
 * every code not listed here stay out. An unknown code fails closed: a row
 * left out costs a consumer one record, a row passed on without the right
 * costs a licence breach.
 *
 * Codes are `data_provider.licence_code` values, compared without case and
 * surrounding spaces; SPDX identifiers are listed beside the registry's own
 * spellings.
 *
 * @api
 */
final class BulkExportLicences
{
    /** @var list<string> */
    private const array REDISTRIBUTABLE = [
        // Same licence on both sides.
        'odbl',
        'odbl-1.0',
        // No obligations at all.
        'cc0-1.0',
        'pddl-1.0',
        'pdm-1.0',
        'public-domain',
        // Attribution only, carried per row (the feature's `source`) and per
        // source in the manifest.
        'cc-by-4.0',
        'etalab-2.0',
        'ogl-uk-3.0',
        'dl-de-by-2.0',
    ];

    public static function allows(string $licenceCode): bool
    {
        return \in_array(strtolower(trim($licenceCode)), self::REDISTRIBUTABLE, true);
    }
}
