<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Town;

use App\Moderation\DeskRider;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

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
 * @phpstan-type TownRow array{answered: bool, qid: ?string, title: ?string, extract: ?string, page_url: ?string, page_lang: ?string, cycling: list<CyclingEvent>, facts: TownFacts, edited: bool, derived: bool, editedBy: ?int, approvedBy: ?int, editor: ?string}
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
            'SELECT ts.answered, ts.qid, ts.title, ts.extract, ts.page_url, ts.page_lang, ts.cycling, ts.facts, ts.edited_at, ts.derived,
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
            // A local text based on the Wikipedia article keeps its credit;
            // a fetched row is the article itself.
            'derived' => null === $row['edited_at'] || (bool) $row['derived'],
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
     * `$userId` wrote the text, null when the writer's account is deleted;
     * `$approvedBy` let it onto the card (the same
     * curator when they wrote it on the town page or proposed it inside their
     * area); `$submissionId` is the approved proposal, null for the town page.
     * `$derived` says the text is based on the Wikipedia article, so the card
     * keeps the article's link and credit; it stands only where the row has an
     * article to credit (fail-closed, as a region's lead).
     */
    public function overrideText(string $osmRef, string $lang, string $extract, bool $derived, ?int $userId, ?string $title, int $approvedBy, ?int $submissionId = null): void
    {
        $this->db->executeStatement(
            'INSERT INTO town_summary (osm_ref, lang, answered, title, extract, cycling, facts, derived, edited_by, approved_by, submission_id, edited_at, checked_at)
             VALUES (:r, :l, TRUE, :t, :e, \'[]\', \'{}\', FALSE, :u, :a, :s, NOW(), NOW())
             ON CONFLICT (osm_ref, lang) DO UPDATE
                SET answered = TRUE, extract = EXCLUDED.extract, title = COALESCE(town_summary.title, EXCLUDED.title),
                    derived = :d AND town_summary.page_url IS NOT NULL,
                    edited_by = EXCLUDED.edited_by, approved_by = EXCLUDED.approved_by, submission_id = EXCLUDED.submission_id,
                    edited_at = NOW(), checked_at = NOW(), based_on_lang = NULL, based_on_at = NULL, based_on_submission = NULL',
            ['r' => $osmRef, 'l' => $lang, 't' => $title, 'e' => $extract, 'd' => $derived, 'u' => $userId, 'a' => $approvedBy, 's' => $submissionId],
            ['d' => ParameterType::BOOLEAN],
        );
    }

    /**
     * The text side of a row as it stands, for putting it back when the
     * approval that replaces it is revoked (moderation-and-contribution.md
     * §5.6); null when there is no row yet.
     *
     * @return array<string, mixed>|null
     */
    public function textSnapshot(string $osmRef, string $lang): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT answered, extract, derived, edited_by, approved_by, submission_id, edited_at, based_on_lang, based_on_at, based_on_submission FROM town_summary WHERE osm_ref = :r AND lang = :l',
            ['r' => $osmRef, 'l' => $lang],
        );
        if (false === $row) {
            return null;
        }

        return [
            'answered' => (bool) $row['answered'],
            'extract' => null === $row['extract'] ? null : (string) $row['extract'],
            'derived' => (bool) $row['derived'],
            'edited_by' => null === $row['edited_by'] ? null : (int) $row['edited_by'],
            'approved_by' => null === $row['approved_by'] ? null : (int) $row['approved_by'],
            'submission_id' => null === $row['submission_id'] ? null : (int) $row['submission_id'],
            'edited_at' => null === $row['edited_at'] ? null : (string) $row['edited_at'],
            'based_on_lang' => null === $row['based_on_lang'] ? null : (string) $row['based_on_lang'],
            'based_on_at' => null === $row['based_on_at'] ? null : (string) $row['based_on_at'],
            'based_on_submission' => null === $row['based_on_submission'] ? null : (int) $row['based_on_submission'],
        ];
    }

    /**
     * What the row's local text translates (moderation-and-contribution.md
     * §3.1e), set after the text is written; null clears it.
     *
     * @param array{lang: string, at: ?string, sub: ?int}|null $basedOn
     */
    public function setBasedOn(string $osmRef, string $lang, ?array $basedOn): void
    {
        $this->db->executeStatement(
            'UPDATE town_summary SET based_on_lang = :bl, based_on_at = :ba, based_on_submission = :bs WHERE osm_ref = :r AND lang = :l',
            ['bl' => $basedOn['lang'] ?? null, 'ba' => $basedOn['at'] ?? null, 'bs' => $basedOn['sub'] ?? null, 'r' => $osmRef, 'l' => $lang],
        );
    }

    /** The submission whose text the row holds now, or null (fetched, the town page's, or no row). */
    public function textSubmission(string $osmRef, string $lang): ?int
    {
        $id = $this->db->fetchOne('SELECT submission_id FROM town_summary WHERE osm_ref = :r AND lang = :l', ['r' => $osmRef, 'l' => $lang]);

        return null === $id || false === $id ? null : (int) $id;
    }

    /**
     * The row's text side put back as a snapshot recorded it; no snapshot (the
     * row did not exist) deletes the row, so the next reader fetches it anew.
     *
     * @param array<string, mixed>|null $snapshot from {@see self::textSnapshot()}
     */
    public function restoreText(string $osmRef, string $lang, ?array $snapshot): void
    {
        if (null === $snapshot) {
            $this->db->executeStatement('DELETE FROM town_summary WHERE osm_ref = :r AND lang = :l', ['r' => $osmRef, 'l' => $lang]);

            return;
        }
        $this->db->executeStatement(
            'UPDATE town_summary SET answered = :a, extract = :e, derived = :d, edited_by = :u, approved_by = :ap, submission_id = :s, edited_at = :at,
                    based_on_lang = :bl, based_on_at = :ba, based_on_submission = :bs
              WHERE osm_ref = :r AND lang = :l',
            [
                'a' => (bool) ($snapshot['answered'] ?? false), 'e' => $snapshot['extract'] ?? null, 'd' => (bool) ($snapshot['derived'] ?? false),
                'u' => $snapshot['edited_by'] ?? null, 'ap' => $snapshot['approved_by'] ?? null, 's' => $snapshot['submission_id'] ?? null,
                'at' => $snapshot['edited_at'] ?? null, 'bl' => $snapshot['based_on_lang'] ?? null, 'ba' => $snapshot['based_on_at'] ?? null,
                'bs' => $snapshot['based_on_submission'] ?? null,
                'r' => $osmRef, 'l' => $lang,
            ],
            ['a' => ParameterType::BOOLEAN, 'd' => ParameterType::BOOLEAN],
        );
    }

    /**
     * Whether the card's text in this language has a Wikipedia article to
     * credit: the row fetched one. A language nobody has opened yet has none.
     */
    public function hasArticle(string $osmRef, string $lang): bool
    {
        return true === $this->db->fetchOne(
            'SELECT page_url IS NOT NULL FROM town_summary WHERE osm_ref = :r AND lang = :l',
            ['r' => $osmRef, 'l' => $lang],
        );
    }

    /**
     * Every language row of one town, for the desk.
     *
     * @return array<string, array{title: ?string, extract: ?string, page_lang: ?string, page_url: ?string, edited: bool, derived: bool, answered: bool}> lang => row
     */
    public function rowsFor(string $osmRef): array
    {
        $out = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT lang, answered, title, extract, page_lang, page_url, edited_at, derived FROM town_summary WHERE osm_ref = :r',
            ['r' => $osmRef],
        );
        foreach ($rows as $row) {
            $out[(string) $row['lang']] = [
                'title' => null === $row['title'] ? null : (string) $row['title'],
                'extract' => null === $row['extract'] ? null : (string) $row['extract'],
                'page_lang' => null === $row['page_lang'] ? null : (string) $row['page_lang'],
                'page_url' => null === $row['page_url'] ? null : (string) $row['page_url'],
                'edited' => null !== $row['edited_at'],
                'derived' => null === $row['edited_at'] || (bool) $row['derived'],
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
