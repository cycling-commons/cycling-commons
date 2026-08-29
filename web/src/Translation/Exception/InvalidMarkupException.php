<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation\Exception;

/**
 * The markup in a proposed translation will not render as written.
 *
 * Either a tag is unbalanced, or it is one the sanitizer strips. Both would
 * pass silently otherwise: the sanitizer repairs the first and removes the
 * second, so the catalogue would end up holding something the translator did
 * not write and nobody would ever be told.
 *
 * Carries the problems so the page can name the tag rather than saying "the
 * HTML is wrong" to somebody who did not know there was any.
 *
 * @see docs/specs/translations.md §7
 *
 * @api
 */
final class InvalidMarkupException extends \RuntimeException
{
    /**
     * @param list<array{level: string, key: string, tag?: string}> $problems
     */
    public function __construct(private readonly array $problems, string $message = 'Proposed translation contains markup that will not render.')
    {
        parent::__construct($message);
    }

    /**
     * @return list<array{level: string, key: string, tag?: string}>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * The first thing to fix, for a one-line flash.
     *
     * @return array{level: string, key: string, tag?: string}
     */
    public function first(): array
    {
        return $this->problems[0] ?? ['level' => 'error', 'key' => 'unbalanced'];
    }
}
