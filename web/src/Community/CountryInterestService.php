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
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly PublicNoteFilter $notes,
    ) {
    }

    /**
     * Upsert so the count stays a count of people.
     *
     * @throws \InvalidArgumentException when the country code is not a real one
     * @throws InvalidNoteException      when the note fails hardening
     */
    public function record(User $user, string $countryCode, bool $willingToCurate, string $note): CountryInterest
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

        $existing = $this->em->getRepository(CountryInterest::class)
            ->findOneBy(['userId' => (int) $user->getId(), 'countryCode' => $cc]);

        $interest = $existing ?? new CountryInterest((int) $user->getId(), $cc);
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

    private function countryExists(string $cc): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM world_country WHERE iso2 = ? LIMIT 1', [$cc]);
    }
}
