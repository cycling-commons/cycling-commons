<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\RegionLead;
use App\Catalog\RegionStory;
use App\Contribution\PlaceText;
use App\Contribution\StoryLinkCheck;
use App\Contribution\TextLineage;
use App\Town\TownSummaryRepository;
use Doctrine\DBAL\Connection;

/**
 * Puts an approved town or region text where readers see it: the town card's
 * local text for that language, the region page's lead for that locale, or
 * the region's "History, nature and culture" text
 * (moderation-and-contribution.md §3.1c).
 *
 * The same stores the curators' direct pens write (the town page and the
 * Regions desk), with who wrote the text, who approved it, which proposal it
 * came from, and whether it is based on the Wikipedia article (`derived`: the
 * credit keeps the article's link, owner 2026-10-01).
 *
 * Where the language has an article to credit, the approving curator decides
 * that last point explicitly: the payload carries `_credit` ({by, at, claim})
 * and `derived` holds the decision. {@see self::creditUndecided()} is the
 * check every approval passes through.
 *
 * @see docs/specs/moderation-and-contribution.md §3.1b
 *
 * @api
 */
final readonly class PlaceTextWriter
{
    public function __construct(private Connection $db, private TownSummaryRepository $towns, private StoryLinkCheck $links, private TextLineage $lineage)
    {
    }

    /**
     * Apply an approved Text submission. Throws on a payload that names no
     * valid target, so the approval fails loudly rather than with no effect.
     */
    public function apply(Submission $submission, int $approvedBy): void
    {
        $proposal = PlaceText::fromPayload($submission->getPayload());
        if (null === $proposal) {
            throw new \InvalidArgumentException(sprintf('Submission %d names no text target', (int) $submission->getId()));
        }
        $payload = $submission->getPayload();
        $basedOn = \is_array($payload['_basedOn'] ?? null) ? TextLineage::basedOnOf(['basedOn' => $payload['_basedOn']]) : null;
        $this->write($submission, $proposal, $approvedBy);
        // What it translates, if anything, then the translators of this
        // language told the original changed (§3.1e).
        $this->stampBasedOn($proposal, $basedOn);
        $town = PlaceText::TOWN === $proposal['target'];
        $slug = $town ? null : (\is_string($payload['slug'] ?? null) ? $payload['slug'] : $this->db->fetchOne('SELECT slug FROM region WHERE id = :id', ['id' => (int) $proposal['ref']]));
        $this->lineage->tellTranslators($submission, $proposal, \is_string($slug) ? $slug : null, $town ? $submission->getTitle() : null);
    }

    /**
     * The approved text into its store.
     *
     * @param array{target: string, ref: string, lang: string, text: string, derived: bool} $proposal
     */
    private function write(Submission $submission, array $proposal, int $approvedBy): void
    {
        if (PlaceText::TOWN === $proposal['target']) {
            $this->towns->overrideText(
                $proposal['ref'],
                $proposal['lang'],
                $proposal['text'],
                $proposal['derived'],
                $submission->getUserId(),
                '' === $submission->getTitle() ? null : mb_substr($submission->getTitle(), 0, 240),
                $approvedBy,
                $submission->getId(),
            );

            return;
        }

        if (PlaceText::STORY === $proposal['target']) {
            $this->setRegionStory((int) $proposal['ref'], $proposal['lang'], $proposal['text'], $submission->getUserId(), $approvedBy, $submission->getId());

            return;
        }

        $this->setRegionLead((int) $proposal['ref'], $proposal['lang'], $proposal['text'], $proposal['derived'], $submission->getUserId(), $approvedBy, $submission->getId());
    }

    /**
     * What a just-written text translates (§3.1e): the town row's columns or
     * the region entry's `basedOn`. A text that translates nothing keeps none.
     *
     * @param array{target: string, ref: string, lang: string, text: string, derived: bool} $proposal
     * @param array{lang: string, at: ?string, sub: ?int}|null                              $basedOn
     */
    private function stampBasedOn(array $proposal, ?array $basedOn): void
    {
        if (PlaceText::TOWN === $proposal['target']) {
            $this->towns->setBasedOn($proposal['ref'], $proposal['lang'], $basedOn);

            return;
        }
        if (null === $basedOn) {
            return;
        }
        $column = PlaceText::STORY === $proposal['target'] ? 'story' : 'context_curated';
        $entries = PlaceText::decode($this->db->fetchOne(sprintf('SELECT %s FROM region WHERE id = :id FOR UPDATE', $column), ['id' => (int) $proposal['ref']])) ?? [];
        if (!\is_array($entries[$proposal['lang']] ?? null)) {
            return;
        }
        $entries[$proposal['lang']]['basedOn'] = $basedOn;
        $this->db->executeStatement(
            sprintf('UPDATE region SET %s = :e WHERE id = :id', $column),
            ['e' => json_encode($entries, \JSON_THROW_ON_ERROR), 'id' => (int) $proposal['ref']],
        );
    }

    /**
     * Whether the text a proposal names has a Wikipedia article to credit in
     * its language: the town card's fetched article, or the region's article
     * (own locale, then English, as the region page cites it). A region's
     * history text has none: it is its writers' own and links its sources.
     *
     * @param array{target: string, ref: string, lang: string, text: string, derived: bool} $proposal
     */
    public function hasSource(array $proposal): bool
    {
        if (PlaceText::TOWN === $proposal['target']) {
            return $this->towns->hasArticle($proposal['ref'], $proposal['lang']);
        }
        if (PlaceText::STORY === $proposal['target']) {
            return false;
        }
        $wiki = $this->db->fetchOne('SELECT context FROM region WHERE id = :id', ['id' => (int) $proposal['ref']]);

        return RegionLead::hasSource(PlaceText::decode($wiki), $proposal['lang']);
    }

    /**
     * True when approving this Text submission still needs the curator's
     * decision on the Wikipedia credit: its language has an article to credit
     * and no curator has recorded the decision (`_credit`) on it.
     */
    public function creditUndecided(Submission $submission): bool
    {
        $payload = $submission->getPayload();
        $proposal = PlaceText::fromPayload($payload);

        return null !== $proposal && !\is_array($payload['_credit'] ?? null) && $this->hasSource($proposal);
    }

    /**
     * True when approving this history text still needs the curator's tick:
     * a link in it is not plainly fine (StoryLinkCheck) and the approval's
     * confirmation (`_links.approved`) does not cover it.
     */
    public function linksUnconfirmed(Submission $submission): bool
    {
        $payload = $submission->getPayload();
        $proposal = PlaceText::fromPayload($payload);
        if (null === $proposal || PlaceText::STORY !== $proposal['target']) {
            return false;
        }
        $problems = $this->links->problems($proposal['text']);

        return [] !== $problems && !StoryLinkCheck::covers($payload, 'approved', $problems);
    }

    /**
     * One locale's lead, the others untouched. An adaptation claim stands only
     * where there is an article to adapt (fail-closed, as on the desk).
     */
    public function setRegionLead(int $regionId, string $locale, string $text, bool $derived, ?int $by, int $approvedBy, ?int $submissionId): void
    {
        $row = $this->db->fetchAssociative('SELECT context, context_curated FROM region WHERE id = :id FOR UPDATE', ['id' => $regionId]);
        if (false === $row) {
            throw new \InvalidArgumentException(sprintf('Unknown region %d', $regionId));
        }
        $wiki = PlaceText::decode($row['context']);
        $curated = RegionLead::withEntry(
            PlaceText::decode($row['context_curated']),
            $locale,
            $text,
            $derived && RegionLead::hasSource($wiki, $locale),
            $by,
            $approvedBy,
            $submissionId,
            new \DateTimeImmutable(),
        );
        $this->db->executeStatement(
            'UPDATE region SET context_curated = :ctx, updated_at = NOW() WHERE id = :id',
            ['ctx' => json_encode($curated, \JSON_THROW_ON_ERROR), 'id' => $regionId],
        );
    }

    /** One locale's history text, the others untouched. */
    public function setRegionStory(int $regionId, string $locale, string $text, ?int $by, int $approvedBy, ?int $submissionId): void
    {
        $row = $this->db->fetchAssociative('SELECT story FROM region WHERE id = :id FOR UPDATE', ['id' => $regionId]);
        if (false === $row) {
            throw new \InvalidArgumentException(sprintf('Unknown region %d', $regionId));
        }
        $story = RegionStory::withEntry(PlaceText::decode($row['story']), $locale, $text, $by, $approvedBy, $submissionId, new \DateTimeImmutable());
        $this->db->executeStatement(
            'UPDATE region SET story = :story, updated_at = NOW() WHERE id = :id',
            ['story' => json_encode($story, \JSON_THROW_ON_ERROR), 'id' => $regionId],
        );
    }
}
