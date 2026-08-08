<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Pagination;

/**
 * The one page-arithmetic helper, and the shape `partials/_pager.html.twig`
 * renders.
 *
 * It started on the moderation desks and moved here the moment a second
 * surface needed it (the account's contributions list and the messages
 * dashboard, 2026-08-08). Every list that grows without bound pages through
 * this, so "page 3 of 12 · 240 in total" means the same arithmetic everywhere
 * and an out-of-range `?page=` lands on the last page rather than on nothing.
 *
 * @see docs/specs/moderation-and-contribution.md §7
 */
final class Pager
{
    /**
     * Clamps `$page` into range, so a bookmarked `?page=99` on a list that has
     * shrunk shows the last page instead of an empty one.
     *
     * @return array{page:int, pages:int, total:int, perPage:int, offset:int, prev:?int, next:?int}
     */
    public static function of(int $page, int $total, int $perPage): array
    {
        $perPage = max(1, $perPage);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);

        return [
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'perPage' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'prev' => $page > 1 ? $page - 1 : null,
            'next' => $page < $pages ? $page + 1 : null,
        ];
    }
}
