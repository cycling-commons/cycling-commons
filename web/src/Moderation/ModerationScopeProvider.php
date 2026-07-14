<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Moderation;

use App\Entity\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Resolves a curator's ModerationScope from moderator_area rows and answers
 * per-item scope checks (moderator-areas spec 2026-07-14). ROLE_ADMIN and
 * row-less curators are global.
 *
 * @api Consumed by the moderation queues, write guards, and the shell label.
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
     * Display names of the user's assigned areas (region names, then country
     * names), for the shell label / audit note / admin form. [] = global.
     *
     * @return list<string>
     */
    public function describe(User $user): array
    {
        $scope = $this->scopeFor($user);
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
            // a code with no world_country row still shows as the raw code
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
