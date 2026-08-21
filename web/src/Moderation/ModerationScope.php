<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use Doctrine\DBAL\ArrayParameterType;

/**
 * Curator's moderation scope. NULL-region items are always in scope.
 *
 * @see docs/specs/moderation-and-contribution.md §9.2
 *
 * @api
 */
final class ModerationScope
{
    /**
     * @param list<int>    $regionIds
     * @param list<string> $countryCodes
     */
    private function __construct(
        public readonly bool $global,
        public readonly array $regionIds,
        public readonly array $countryCodes,
    ) {
    }

    public static function global(): self
    {
        return new self(true, [], []);
    }

    /**
     * @param list<int>    $regionIds
     * @param list<string> $countryCodes
     */
    public static function limited(array $regionIds, array $countryCodes): self
    {
        return new self(
            false,
            array_map(intval(...), $regionIds),
            array_map(strtoupper(...), $countryCodes),
        );
    }

    /**
     * Scoping WHERE for `$alias.region_id`. `$alias` must be a hardcoded identifier.
     *
     * @return array{sql: string, params: array<string, mixed>, types: array<string, mixed>}
     */
    public function sqlFragment(string $alias): array
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/i', $alias)) {
            throw new \InvalidArgumentException('Alias must be a literal SQL identifier.');
        }
        if ($this->global) {
            return ['sql' => '', 'params' => [], 'types' => []];
        }

        return [
            'sql' => "({$alias}.region_id IS NULL OR {$alias}.region_id IN (:sc_rids) OR EXISTS ("
                ."SELECT 1 FROM region scr WHERE scr.id = {$alias}.region_id AND scr.country_code IN (:sc_ccs)))",
            'params' => [
                'sc_rids' => [] !== $this->regionIds ? $this->regionIds : [-1],
                'sc_ccs' => [] !== $this->countryCodes ? $this->countryCodes : ['--'],
            ],
            'types' => [
                'sc_rids' => ArrayParameterType::INTEGER,
                'sc_ccs' => ArrayParameterType::STRING,
            ],
        ];
    }
}
