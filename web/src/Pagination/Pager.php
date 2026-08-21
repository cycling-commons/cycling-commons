<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Pagination;

/**
 * Page arithmetic for `partials/_pager.html.twig`. Out-of-range `?page=` clamps to the last page.
 */
final class Pager
{
    /**
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
