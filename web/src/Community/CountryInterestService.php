<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Community;

use App\Community\Entity\CountryInterest;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Country-interest upsert. Counts stay owner-only for v1.
 *
 * @see docs/specs/moderation-and-contribution.md §11
 *
 * @api
 */
final class CountryInterestService
{
    /** A place name, not a paragraph: "Texas", "Provence-Alpes-Cote d'Azur". */
    public const int MAX_REGION = 120;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly PublicNoteFilter $notes,
    ) {
    }

    /**
     * Upsert so the count stays a count of people.
     *
     * One row per person per area, the area being '' for a whole country. A
     * rider who asks for Texas and later for New Mexico is two signals and one
     * person; asking for Texas twice is one of each.
     *
     * @param string $regionName the area inside the country, '' for all of it
     *
     * @throws \InvalidArgumentException when the country code is not a real one
     * @throws InvalidNoteException      when the note or the area fails hardening
     */
    public function record(User $user, string $countryCode, bool $willingToCurate, string $note, string $regionName = ''): CountryInterest
    {
        $cc = strtoupper(trim($countryCode));
        if (1 !== preg_match('/^[A-Z]{2}$/', $cc) || !$this->countryExists($cc)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a known country code.', $countryCode));
        }

        // Decide emptiness after clean(): invisible-only strings become empty.
        $rawNote = trim($note);
        $clean = null;
        if ('' !== $rawNote) {
            $clean = $this->notes->clean($note, PublicNoteFilter::MAX_NOTE);
            if ('' === $clean) {
                $clean = null;
            }
        }

        // Through the same filter as the note, and for the same reason: this
        // reaches a curator's desk as text somebody else wrote.
        $region = trim($regionName);
        if ('' !== $region) {
            $region = trim($this->notes->clean($region, self::MAX_REGION));
        }

        $existing = $this->em->getRepository(CountryInterest::class)
            ->findOneBy(['userId' => (int) $user->getId(), 'countryCode' => $cc, 'regionName' => $region]);

        $interest = $existing ?? new CountryInterest((int) $user->getId(), $cc, $region);
        $interest->setWillingToCurate($willingToCurate || $interest->isWillingToCurate());
        if (null !== $clean) {
            $interest->setNote($clean);
        }

        $this->em->persist($interest);
        $this->em->flush();

        return $interest;
    }

    /**
     * @return list<array{countryCode: string, total: int, willing: int}>
     */
    public function counts(): array
    {
        /** @var list<array{country_code: string, total: int|string, willing: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT country_code,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE willing_to_curate) AS willing
             FROM country_interest
             GROUP BY country_code
             ORDER BY COUNT(*) DESC, country_code ASC',
        );

        return array_map(
            static fn (array $r): array => [
                'countryCode' => $r['country_code'],
                'total' => (int) $r['total'],
                'willing' => (int) $r['willing'],
            ],
            $rows,
        );
    }

    /**
     * The named areas inside one country, busiest first.
     *
     * A country total answers "should we onboard this country". It cannot
     * answer "which part of it", which for the United States or France is the
     * only question that decides anything. Rows asking for the country as a
     * whole are left out: they are already the country total.
     *
     * @return list<array{regionName: string, total: int, willing: int}>
     */
    public function regionCounts(string $countryCode): array
    {
        /** @var list<array{region_name: string, total: int|string, willing: int|string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT region_name,
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE willing_to_curate) AS willing
             FROM country_interest
             WHERE country_code = ? AND region_name <> ''
             GROUP BY region_name
             ORDER BY COUNT(*) DESC, region_name ASC",
            [strtoupper(trim($countryCode))],
        );

        return array_map(
            static fn (array $r): array => [
                'regionName' => $r['region_name'],
                'total' => (int) $r['total'],
                'willing' => (int) $r['willing'],
            ],
            $rows,
        );
    }

    /**
     * Every signal on file, newest first, with the person behind it.
     *
     * One statement rather than a count query and a lookup per row: this
     * table is a planning signal, not traffic, and a reviewer deciding where
     * to grow needs the words and the volunteers in front of them, not a
     * number they then have to go and explain.
     *
     * The display name can be absent and the address cannot: an account
     * always has one, and it is how somebody who offered to curate is
     * actually reached.
     *
     * @return list<array{countryCode: string, regionName: string, willing: bool, note: ?string, displayName: ?string, email: ?string, updatedAt: string}>
     */
    public function requests(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT ci.country_code, ci.region_name, ci.willing_to_curate, ci.note,
                    ci.updated_at, u.display_name, u.email
               FROM country_interest ci
               LEFT JOIN users u ON u.id = ci.user_id
              ORDER BY ci.updated_at DESC, ci.id DESC',
        );

        return array_map(
            static fn (array $r): array => [
                'countryCode' => (string) $r['country_code'],
                'regionName' => (string) $r['region_name'],
                'willing' => (bool) $r['willing_to_curate'],
                'note' => null === $r['note'] ? null : (string) $r['note'],
                'displayName' => null === $r['display_name'] ? null : (string) $r['display_name'],
                'email' => null === $r['email'] ? null : (string) $r['email'],
                'updatedAt' => (string) $r['updated_at'],
            ],
            $rows,
        );
    }

    private function countryExists(string $cc): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM world_country WHERE iso2 = ? LIMIT 1', [$cc]);
    }
}
