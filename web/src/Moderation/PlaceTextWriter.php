<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\RegionLead;
use App\Contribution\PlaceText;
use App\Town\TownSummaryRepository;
use Doctrine\DBAL\Connection;

/**
 * Puts an approved town or region text where readers see it: the town card's
 * local text for that language, or the region page's lead for that locale.
 *
 * The same stores the curators' direct pens write (the town page and the
 * Regions desk), with who wrote the text, who approved it and which proposal
 * it came from.
 *
 * @see docs/specs/moderation-and-contribution.md §3.1b
 *
 * @api
 */
final readonly class PlaceTextWriter
{
    public function __construct(private Connection $db, private TownSummaryRepository $towns)
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

        if (PlaceText::TOWN === $proposal['target']) {
            $this->towns->overrideText(
                $proposal['ref'],
                $proposal['lang'],
                $proposal['text'],
                $submission->getUserId(),
                '' === $submission->getTitle() ? null : mb_substr($submission->getTitle(), 0, 240),
                $approvedBy,
                $submission->getId(),
            );

            return;
        }

        $this->setRegionLead((int) $proposal['ref'], $proposal['lang'], $proposal['text'], $proposal['derived'], $submission->getUserId(), $approvedBy, $submission->getId());
    }

    /**
     * One locale's lead, the others untouched. An adaptation claim stands only
     * where there is an article to adapt (fail-closed, as on the desk).
     */
    public function setRegionLead(int $regionId, string $locale, string $text, bool $derived, int $by, int $approvedBy, ?int $submissionId): void
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
}
