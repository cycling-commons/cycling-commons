<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Resolves a curator's ModerationScope. ROLE_ADMIN and row-less curators are global.
 *
 * @see docs/specs/moderation-and-contribution.md §9.2
 *
 * @api
 */
final class ModerationScopeProvider
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function scopeFor(User $user): ModerationScope
    {
        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return ModerationScope::global();
        }
        /** @var list<array{region_id: int|string|null, country_code: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT region_id, country_code FROM moderator_area WHERE user_id = :uid',
            ['uid' => (int) $user->getId()],
        );
        if ([] === $rows) {
            return ModerationScope::global();
        }
        $rids = [];
        $ccs = [];
        foreach ($rows as $r) {
            if (null !== $r['region_id']) {
                $rids[] = (int) $r['region_id'];
            } elseif (null !== $r['country_code'] && '' !== $r['country_code']) {
                $ccs[] = (string) $r['country_code'];
            }
        }

        return ModerationScope::limited($rids, $ccs);
    }

    /**
     * PHP twin of ModerationScope::sqlFragment() — the write-guard predicate.
     *
     * @see docs/specs/moderation-and-contribution.md §9.2
     */
    public function allowsRegion(ModerationScope $scope, ?int $regionId): bool
    {
        if ($scope->global || null === $regionId) {
            return true;
        }
        if (\in_array($regionId, $scope->regionIds, true)) {
            return true;
        }
        if ([] === $scope->countryCodes) {
            return false;
        }
        $cc = $this->db->fetchOne('SELECT country_code FROM region WHERE id = :id', ['id' => $regionId]);

        return \is_string($cc) && \in_array(strtoupper($cc), $scope->countryCodes, true);
    }

    /**
     * Every region id this scope covers, or null when it covers all of them.
     * The same rule as {@see self::allowsRegion()}, answered for the whole map
     * at once so a page can decide per region without a query each time.
     *
     * @return list<int>|null null = global
     */
    public function allowedRegionIds(ModerationScope $scope): ?array
    {
        if ($scope->global) {
            return null;
        }
        $ids = $scope->regionIds;
        if ([] !== $scope->countryCodes) {
            /** @var list<int|string> $byCountry */
            $byCountry = $this->db->fetchFirstColumn(
                'SELECT id FROM region WHERE UPPER(country_code) IN (:cc)',
                ['cc' => $scope->countryCodes],
                ['cc' => ArrayParameterType::STRING],
            );
            $ids = [...$ids, ...array_map(intval(...), $byCountry)];
        }

        return array_values(array_unique($ids));
    }

    /**
     * Display names of assigned areas. [] = global.
     *
     * @return list<string>
     */
    public function describe(User $user, ?ModerationScope $scope = null): array
    {
        $scope ??= $this->scopeFor($user);
        if ($scope->global) {
            return [];
        }
        $names = [];
        if ([] !== $scope->regionIds) {
            /** @var list<string> $regionNames */
            $regionNames = $this->db->fetchFirstColumn(
                'SELECT name FROM region WHERE id IN (:ids) ORDER BY name',
                ['ids' => $scope->regionIds],
                ['ids' => ArrayParameterType::INTEGER],
            );
            $names = [...$names, ...$regionNames];
        }
        if ([] !== $scope->countryCodes) {
            /** @var list<string> $countryNames */
            $countryNames = $this->db->fetchFirstColumn(
                'SELECT name FROM world_country WHERE iso2 IN (:ccs) ORDER BY name',
                ['ccs' => $scope->countryCodes],
                ['ccs' => ArrayParameterType::STRING],
            );
            // A code with no world_country row still shows as the raw code.
            $found = $this->db->fetchFirstColumn(
                'SELECT iso2 FROM world_country WHERE iso2 IN (:ccs)',
                ['ccs' => $scope->countryCodes],
                ['ccs' => ArrayParameterType::STRING],
            );
            $names = [...$names, ...$countryNames, ...array_values(array_diff($scope->countryCodes, $found))];
        }

        return $names;
    }
}
