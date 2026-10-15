<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Links;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The verdict table (docs/specs/website-liveness.md section 4): one row per
 * website host with the last verdict and when it is due again. Written by
 * the sweep, the wizard and the provider ingest; read in bulk by the serve
 * paths, where a host never seen answers unknown.
 *
 * @api
 */
final class WebsiteVerdictStore
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * The host of an address in SQL, as WebsiteVerdict::hostOf() takes it:
     * lower-cased, port dropped; NULL when the expression is not an http(s)
     * address. The serve paths join `website_check` on it.
     */
    public static function hostSql(string $urlExpr): string
    {
        return "lower(substring($urlExpr from '^[hH][tT][tT][pP][sS]?://([^/:?#]+)'))";
    }

    /** Upsert by host: the address checked, the verdict, the status, the reason, and the next due date from the verdict's period. */
    public function record(string $host, string $url, WebsiteVerdict $verdict, \DateTimeImmutable $now): void
    {
        $this->db->executeStatement(
            'INSERT INTO website_check (host, url, verdict, status, reason, checked_at, next_check_at)
             VALUES (:host, :url, :verdict, :status, :reason, :now, :due)
             ON CONFLICT (host) DO UPDATE
               SET url = EXCLUDED.url, verdict = EXCLUDED.verdict, status = EXCLUDED.status,
                   reason = EXCLUDED.reason, checked_at = EXCLUDED.checked_at, next_check_at = EXCLUDED.next_check_at',
            [
                'host' => $host,
                'url' => mb_substr($url, 0, 500),
                'verdict' => $verdict->verdict,
                'status' => $verdict->status,
                'reason' => '' === $verdict->reason ? null : mb_substr($verdict->reason, 0, 40),
                'now' => $now,
                'due' => $now->modify('+'.WebsiteVerdict::daysUntilRecheck($verdict->verdict).' days'),
            ],
            ['now' => 'datetime_immutable', 'due' => 'datetime_immutable'],
        );
    }

    /**
     * The last verdict per host; a host never seen is unknown, never missing.
     *
     * @param list<string> $hosts
     *
     * @return array<string, string> host => verdict
     */
    public function verdictFor(array $hosts): array
    {
        if ([] === $hosts) {
            return [];
        }
        /** @var array<string, string> $known */
        $known = $this->db->fetchAllKeyValue(
            'SELECT host, verdict FROM website_check WHERE host IN (:hosts)',
            ['hosts' => array_values(array_unique($hosts))],
            ['hosts' => ArrayParameterType::STRING],
        );
        $out = [];
        foreach ($hosts as $host) {
            $out[$host] = $known[$host] ?? WebsiteVerdict::UNKNOWN;
        }

        return $out;
    }

    /** The verdict of a host checked within its period, else null: the wizard and the ingest fetch only then. */
    public function fresh(string $host, \DateTimeImmutable $now): ?string
    {
        $verdict = $this->db->fetchOne(
            'SELECT verdict FROM website_check WHERE host = :host AND next_check_at > :now',
            ['host' => $host, 'now' => $now],
            ['now' => 'datetime_immutable'],
        );

        return \is_string($verdict) ? $verdict : null;
    }

    /**
     * The hosts the serve paths must hide: dead or parked, among the given.
     *
     * @param list<string> $hosts
     *
     * @return list<string>
     */
    public function hidden(array $hosts): array
    {
        $out = [];
        foreach ($this->verdictFor($hosts) as $host => $verdict) {
            if (WebsiteVerdict::hides($verdict)) {
                $out[] = $host;
            }
        }

        return $out;
    }
}
