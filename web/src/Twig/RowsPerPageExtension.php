<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Account\RowsPerPage;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_rows_per_page_options()` for every pager.
 *
 * @see docs/specs/account-and-auth.md §9
 *
 * @api
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
