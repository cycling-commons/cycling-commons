<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use Doctrine\DBAL\ArrayParameterType;

/**
 * The acting curator's moderation scope (moderator-areas spec 2026-07-14):
 * global (admin, or a curator with no area rows) or a union of region ids +
 * country codes. One SQL fragment scopes the queues; the provider's
 * allowsRegion() is the matching PHP predicate for write guards. NULL-region
 * items are ALWAYS in scope — intake keeps outside-all-regions proposals
 * reviewable by everyone.
 *
 * @api Moderation vocabulary.
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
            array_values(array_map(intval(...), $regionIds)),
            array_values(array_map(strtoupper(...), $countryCodes)),
        );
    }

    /**
     * Scoping WHERE fragment for a table aliased $alias that carries a
     * region_id column. Empty when global. Empty lists bind impossible
     * sentinels so the IN () clauses stay valid SQL.
     *
     * $alias is interpolated into raw SQL: it MUST be a hardcoded literal
     * identifier at the call site, never user/request-derived. A cheap
     * allowlist guard enforces the identifier shape.
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
