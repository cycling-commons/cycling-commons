<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Community;

use App\Community\Entity\CountryInterest;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Support\SupportMailer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Countries;

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

    /** The inbox channel of a "your country is live" message (varchar(12)). */
    public const string LIVE_CHANNEL = 'country_live';

    /**
     * The requests a "your country is live" message answers: the rider still
     * has an account, has not been told, and asked before the country went
     * live. A request made after that asks for more inside a live country
     * (Ohio in the United States), which the country going live did not answer.
     */
    private const string NOTIFIABLE = "ci.user_id IS NOT NULL AND ci.notified_at IS NULL
        AND c.status = 'live' AND ci.created_at < c.live_at";

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly PublicNoteFilter $notes,
        private readonly SupportMailer $mailer,
        private readonly MessageService $messages,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
    ) {
    }

    /**
     * Upsert so the count stays a count of people.
     *
     * One row per person per area, the area being '' for a whole country. A
     * rider who asks for Texas and later for New Mexico is two signals and one
     * person; asking for Texas twice is one of each.
     *
     * The support address hears about a request once it is committed, and
     * only when it says something new: a row that did not exist, or a rider
     * who now offers to curate where before they only asked. Asking again for
     * the same area, or changing the note, updates the row and sends nothing.
     * Together with the per-account `country_interest` limiter (10 a day) that
     * is the whole flood rule: one account can produce at most one mail per
     * distinct area it names, and never the same one twice.
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
        $wasWilling = null !== $existing && $existing->isWillingToCurate();
        $interest->setWillingToCurate($willingToCurate || $interest->isWillingToCurate());
        if (null !== $clean) {
            $interest->setNote($clean);
        }

        $this->em->persist($interest);
        $this->em->flush();

        if (null === $existing || (!$wasWilling && $interest->isWillingToCurate())) {
            $this->mailer->notifyCountryRequest($interest, $user, $this->countryTotal($cc));
        }

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
     * @return list<array{id: int, countryCode: string, regionName: string, willing: bool, note: ?string, displayName: ?string, email: ?string, updatedAt: string, notifiedAt: ?string}>
     */
    public function requests(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT ci.id, ci.country_code, ci.region_name, ci.willing_to_curate, ci.note,
                    ci.updated_at, ci.notified_at, u.display_name, u.email
               FROM country_interest ci
               LEFT JOIN users u ON u.id = ci.user_id
              ORDER BY ci.updated_at DESC, ci.id DESC',
        );

        return array_map(
            static fn (array $r): array => [
                'id' => (int) $r['id'],
                'countryCode' => (string) $r['country_code'],
                'regionName' => (string) $r['region_name'],
                'willing' => (bool) $r['willing_to_curate'],
                'note' => null === $r['note'] ? null : (string) $r['note'],
                'displayName' => null === $r['display_name'] ? null : (string) $r['display_name'],
                'email' => null === $r['email'] ? null : (string) $r['email'],
                'updatedAt' => (string) $r['updated_at'],
                'notifiedAt' => null === $r['notified_at'] ? null : (string) $r['notified_at'],
            ],
            $rows,
        );
    }

    /**
     * Requests nobody has answered yet: every row without `notified_at`,
     * including a deleted account's, which still counts as asked for.
     */
    public function openCount(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM country_interest WHERE notified_at IS NULL');
    }

    /**
     * Per live country, how many riders are waiting to hear it went live.
     *
     * @return array<string, int> country code => riders
     */
    public function notifiable(): array
    {
        /** @var array<string, int|string> $rows */
        $rows = $this->db->fetchAllKeyValue(
            'SELECT ci.country_code, COUNT(DISTINCT ci.user_id)
               FROM country_interest ci JOIN country c ON c.code = ci.country_code
              WHERE '.self::NOTIFIABLE.'
              GROUP BY ci.country_code',
        );

        return array_map(intval(...), $rows);
    }

    /**
     * Tell every rider who asked for a country that it is live, once.
     *
     * One inbox message per rider, which also emails them, in their own
     * language; a rider who asked for the country and for an area inside it
     * hears once. The rows are stamped in the same transaction as the
     * messages, so pressing the button again, or twice at once, tells nobody
     * twice. A reply to the rider's own request, like the curator receipt,
     * so it does not wait on the news opt-in.
     *
     * @return int the riders told
     */
    public function notifyLive(string $countryCode): int
    {
        $cc = strtoupper(trim($countryCode));

        return $this->em->wrapInTransaction(function () use ($cc): int {
            /** @var list<array{id: int|string, user_id: int|string, locale: ?string}> $rows */
            $rows = $this->db->fetchAllAssociative(
                'SELECT ci.id, ci.user_id, u.locale
                   FROM country_interest ci
                   JOIN country c ON c.code = ci.country_code
                   JOIN users u ON u.id = ci.user_id
                  WHERE ci.country_code = :cc AND '.self::NOTIFIABLE.'
                  ORDER BY ci.id
                    FOR UPDATE OF ci',
                ['cc' => $cc],
            );
            if ([] === $rows) {
                return 0;
            }

            $riders = [];
            foreach ($rows as $r) {
                $riders[(int) $r['user_id']] ??= ['id' => (int) $r['id'], 'locale' => $r['locale'] ?? $this->defaultLocale];
            }
            foreach ($riders as $userId => $rider) {
                $this->messages->sendSystem(
                    $userId,
                    UserMessageKind::CountryLive,
                    self::LIVE_CHANNEL,
                    $rider['id'],
                    $cc,
                    'join.message.country_live',
                    ['%country%' => Countries::exists($cc) ? Countries::getName($cc, $rider['locale']) : $cc],
                );
            }

            $this->db->executeStatement(
                'UPDATE country_interest SET notified_at = NOW() WHERE id IN (:ids)',
                ['ids' => array_map(static fn (array $r): int => (int) $r['id'], $rows)],
                ['ids' => ArrayParameterType::INTEGER],
            );
            $this->em->flush();

            return \count($riders);
        });
    }

    /** Every request on file for one country, the named areas included, as the admin desk counts them. */
    private function countryTotal(string $cc): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM country_interest WHERE country_code = ?', [$cc]);
    }

    private function countryExists(string $cc): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM world_country WHERE iso2 = ? LIMIT 1', [$cc]);
    }
}
