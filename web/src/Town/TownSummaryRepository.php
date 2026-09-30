<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Town;

use App\Moderation\DeskRider;
use Doctrine\DBAL\Connection;

/**
 * The town card's cache: one row per (OpenStreetMap ref, reader language).
 *
 * Same claim / record / release shape as the P18 cache, for the same reason:
 * two readers opening Antwerp in the same second must ask Wikipedia once, and
 * an unanswered claim whose job died must not spin forever.
 *
 * @see docs/specs/map-and-search.md §6.5
 *
 * @phpstan-type CyclingEvent array{qid: string, label: string, rels: list<string>, n: int, last: ?int, url: ?string}
 * @phpstan-type TownFacts array{founded?: array{year: int, precision: int}, population?: array{n: int, year: ?int}}
 * @phpstan-type TownRow array{answered: bool, qid: ?string, title: ?string, extract: ?string, page_url: ?string, page_lang: ?string, cycling: list<CyclingEvent>, facts: TownFacts, edited: bool, editedBy: ?int, approvedBy: ?int, editor: ?string}
 *
 * @api
 */
final readonly class TownSummaryRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** True only for the caller that created the row, which is the one that asks. */
    public function claim(string $osmRef, string $lang): bool
    {
        return 1 === $this->db->executeStatement(
            'INSERT INTO town_summary (osm_ref, lang, answered, checked_at) VALUES (:r, :l, FALSE, NOW()) ON CONFLICT (osm_ref, lang) DO NOTHING',
            ['r' => $osmRef, 'l' => $lang],
        );
    }

    /** @return TownRow|null */
    public function find(string $osmRef, string $lang): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT ts.answered, ts.qid, ts.title, ts.extract, ts.page_url, ts.page_lang, ts.cycling, ts.facts, ts.edited_at,
                    ts.edited_by, ts.approved_by, u.display_name, u.public_profile, u.pseudonym, u.uuid
               FROM town_summary ts LEFT JOIN users u ON u.id = ts.edited_by
              WHERE ts.osm_ref = :r AND ts.lang = :l',
            ['r' => $osmRef, 'l' => $lang],
        );
        if (false === $row) {
            return null;
        }
        /** @var list<CyclingEvent> $cycling */
        $cycling = \is_string($row['cycling']) ? (array) json_decode($row['cycling'], true, 8, \JSON_THROW_ON_ERROR) : [];
        /** @var TownFacts $facts */
        $facts = \is_string($row['facts']) ? (array) json_decode($row['facts'], true, 8, \JSON_THROW_ON_ERROR) : [];

        return [
            'answered' => (bool) $row['answered'],
            'qid' => null === $row['qid'] ? null : (string) $row['qid'],
            'title' => null === $row['title'] ? null : (string) $row['title'],
            'extract' => null === $row['extract'] ? null : (string) $row['extract'],
            'page_url' => null === $row['page_url'] ? null : (string) $row['page_url'],
            'page_lang' => null === $row['page_lang'] ? null : (string) $row['page_lang'],
            'cycling' => $cycling,
            'facts' => $facts,
            'edited' => null !== $row['edited_at'],
            'editedBy' => null === $row['edited_by'] ? null : (int) $row['edited_by'],
            'approvedBy' => null === $row['approved_by'] ? null : (int) $row['approved_by'],
            // What a public page may call the writer: their name when their
            // profile is public, else their rider# handle (DeskRider::of()).
            'editor' => null === $row['edited_by'] ? null
                : DeskRider::of($row['pseudonym'], $row['display_name'], $row['public_profile'], $row['uuid'])['name'],
        ];
    }

    /**
     * The sources replied. A null text is the answer "no Wikipedia page", kept.
     *
     * @param array{title: string, extract: string, url: string, lang: string}|null $text
     * @param list<CyclingEvent>                                                    $cycling
     * @param TownFacts                                                             $facts
     */
    public function record(string $osmRef, string $lang, ?string $qid, ?array $text, array $cycling, array $facts = []): void
    {
        $this->db->executeStatement(
            'UPDATE town_summary SET answered = TRUE, qid = :q, title = :t, extract = :e, page_url = :u, page_lang = :pl, cycling = :c, facts = :f, checked_at = NOW()
              WHERE osm_ref = :r AND lang = :l',
            [
                'f' => json_encode((object) $facts, \JSON_THROW_ON_ERROR),
                'q' => $qid,
                't' => $text['title'] ?? null,
                'e' => $text['extract'] ?? null,
                'u' => $text['url'] ?? null,
                'pl' => $text['lang'] ?? null,
                'c' => json_encode($cycling, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'r' => $osmRef,
                'l' => $lang,
            ],
        );
    }

    /**
     * Local words replace the fetched paragraph. From now on the row is local:
     * answered, marked edited, and no fetch touches it again. Creates the row
     * when no reader has opened that language yet.
     *
     * `$userId` wrote the text; `$approvedBy` let it onto the card (the same
     * curator when they wrote it on the town page or proposed it inside their
     * area); `$submissionId` is the approved proposal, null for the town page.
     */
    public function overrideText(string $osmRef, string $lang, string $extract, int $userId, ?string $title, int $approvedBy, ?int $submissionId = null): void
    {
        $this->db->executeStatement(
            'INSERT INTO town_summary (osm_ref, lang, answered, title, extract, cycling, facts, edited_by, approved_by, submission_id, edited_at, checked_at)
             VALUES (:r, :l, TRUE, :t, :e, \'[]\', \'{}\', :u, :a, :s, NOW(), NOW())
             ON CONFLICT (osm_ref, lang) DO UPDATE
                SET answered = TRUE, extract = EXCLUDED.extract, title = COALESCE(town_summary.title, EXCLUDED.title),
                    edited_by = EXCLUDED.edited_by, approved_by = EXCLUDED.approved_by, submission_id = EXCLUDED.submission_id,
                    edited_at = NOW(), checked_at = NOW()',
            ['r' => $osmRef, 'l' => $lang, 't' => $title, 'e' => $extract, 'u' => $userId, 'a' => $approvedBy, 's' => $submissionId],
        );
    }

    /**
     * Every language row of one town, for the desk.
     *
     * @return array<string, array{title: ?string, extract: ?string, page_lang: ?string, page_url: ?string, edited: bool, answered: bool}> lang => row
     */
    public function rowsFor(string $osmRef): array
    {
        $out = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT lang, answered, title, extract, page_lang, page_url, edited_at FROM town_summary WHERE osm_ref = :r',
            ['r' => $osmRef],
        );
        foreach ($rows as $row) {
            $out[(string) $row['lang']] = [
                'title' => null === $row['title'] ? null : (string) $row['title'],
                'extract' => null === $row['extract'] ? null : (string) $row['extract'],
                'page_lang' => null === $row['page_lang'] ? null : (string) $row['page_lang'],
                'page_url' => null === $row['page_url'] ? null : (string) $row['page_url'],
                'edited' => null !== $row['edited_at'],
                'answered' => (bool) $row['answered'],
            ];
        }

        return $out;
    }

    /** A source did not reply: drop the unanswered claim so a later visit asks again. */
    public function release(string $osmRef, string $lang): void
    {
        $this->db->executeStatement(
            'DELETE FROM town_summary WHERE osm_ref = :r AND lang = :l AND answered = FALSE',
            ['r' => $osmRef, 'l' => $lang],
        );
    }
}
