<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Account\RowsPerPage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_rows_per_page_options()` — the page-length choices, for the select every
 * pager carries.
 *
 * A function rather than a variable each controller passes: the pager partial
 * is included from eleven templates across five controllers, and a list of
 * enum cases is not something any of them should have to remember to hand it.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api Auto-registered Twig extension.
 */
final class RowsPerPageExtension extends AbstractExtension
{
    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cc_rows_per_page_options', static fn (): array => array_map(
                static fn (RowsPerPage $r): array => ['value' => $r->value, 'label' => $r->labelKey()],
                RowsPerPage::cases(),
            )),
        ];
    }
}
