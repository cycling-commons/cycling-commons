<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Import;

/**
 * Walloon harvest `prov` display names → ISO 3166-2 (`world_subdivision.code`).
 *
 * @api
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
