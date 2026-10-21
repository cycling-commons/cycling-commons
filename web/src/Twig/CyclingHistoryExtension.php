<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Catalog\CyclingHistorySwitch;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cycling_history_live()`: whether the footer and /pages link the cycling
 * history timeline. A link to a page that answers not found is a dead end.
 *
 * @see docs/specs/cycling-history.md §5
 *
 * @api
 */
final class CyclingHistoryExtension extends AbstractExtension
{
    public function __construct(private readonly CyclingHistorySwitch $switch)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('cycling_history_live', $this->switch->isLive(...))];
    }
}
