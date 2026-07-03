<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Import;

/**
 * Walloon province display names (as the harvest emits them in `prov`)
 * → ISO 3166-2 subdivision codes (world_subdivision.code).
 *
 * @api Used by the catalog importer.
 */
final class ProvinceMap
{
    public const array CODES = [
        'Brabant wallon' => 'BE-WBR',
        'Hainaut' => 'BE-WHT',
        'Liège' => 'BE-WLG',
        'Luxembourg' => 'BE-WLX',
        'Namur' => 'BE-WNA',
    ];
}
