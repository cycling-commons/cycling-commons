<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
