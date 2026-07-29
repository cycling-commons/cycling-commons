<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

use App\Community\Entity\CountryInterest;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 2026-07-29-country-requests-and-curator-signup-design.md §5.1.
 *
 * Counts stay owner-only for v1 (§12.2): a public counter discourages when the
 * numbers are small — which they will be on an empty map — and becomes worth
 * gaming, while the signal's whole value is honest input to an onboarding
 * decision.
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
     * Upsert: re-submitting updates the flag and note rather than creating a
     * second row, so the count stays a count of PEOPLE.
     *
     * @throws \InvalidArgumentException when the country code is not a real one
     * @throws InvalidNoteException      when the note fails §7 hardening
     */
    public function record(User $user, string $countryCode, bool $willingToCurate, string $note): CountryInterest
    {
        $cc = strtoupper(trim($countryCode));
        if (1 !== preg_match('/^[A-Z]{2}$/', $cc) || !$this->countryExists($cc)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a known country code.', $countryCode));
        }

        // Decide emptiness AFTER cleaning: PublicNoteFilter::clean() can reduce
        // invisible-only strings (U+200B, etc.) to empty, which we must not treat
        // as a legitimate note update. Only update note if cleaning produced non-empty.
        $rawNote = trim($note);
        $clean = null;
        if ('' !== $rawNote) {
            $clean = $this->notes->clean($note, PublicNoteFilter::MAX_NOTE);
            // If cleaning reduced to empty, treat as no-change (keep existing note)
            if ('' === $clean) {
                $clean = null;
            }
        }

        $existing = $this->em->getRepository(CountryInterest::class)
            ->findOneBy(['userId' => (int) $user->getId(), 'countryCode' => $cc]);

        $interest = $existing ?? new CountryInterest((int) $user->getId(), $cc);
        // Willingness only ever goes up on a repeat submit: someone who already
        // volunteered has not withdrawn by filling the plain form again.
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
