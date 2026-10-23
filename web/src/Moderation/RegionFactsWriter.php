<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\RegionFacts;
use App\Contribution\PlaceText;
use Doctrine\DBAL\Connection;

/**
 * Puts an approved proposal of a region's figures on its page: each figure
 * it names into `region.facts`, stamped with the proposal, and takes them
 * back when the approval is revoked (moderation-and-contribution.md §3.1g,
 * §5.6). PlaceTextWriter hands it every `facts` proposal.
 *
 * @api
 */
final readonly class RegionFactsWriter
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Each figure into its entry, the other figures untouched. What stood
     * before is kept on the payload (`_before.facts`), so a revoke puts back
     * exactly that. Throws on a payload with a figure that may not be
     * stored, so the approval fails loudly rather than storing it.
     */
    public function apply(Submission $submission): void
    {
        $proposal = PlaceText::factsOf($submission->getPayload());
        if (null === $proposal) {
            throw new \InvalidArgumentException(sprintf('Submission %d names no region figures', (int) $submission->getId()));
        }
        foreach ($proposal['facts'] as $field => $value) {
            if (!RegionFacts::isValid($field, $value)) {
                throw new \InvalidArgumentException(sprintf('Submission %d has an invalid %s', (int) $submission->getId(), $field));
            }
        }
        $stored = $this->stored((int) $proposal['ref'], true);
        $before = [];
        $at = new \DateTimeImmutable();
        foreach ($proposal['facts'] as $field => $value) {
            $before[$field] = RegionFacts::entry($stored, $field);
            $stored = RegionFacts::withEntry($stored, $field, $value, $submission->getId(), $at);
        }
        $this->save((int) $proposal['ref'], $stored);

        $payload = $submission->getPayload();
        $payload['_before'] = ['facts' => $before];
        $submission->setPayload($payload);
    }

    /**
     * Undo an approved proposal: each figure it set goes back to what stood
     * before. Only while every one is still this proposal's: a figure set
     * since is not overwritten, and the revoke is refused.
     *
     * @throws RevokeRefused when a figure was set since
     */
    public function revert(Submission $submission): void
    {
        $proposal = PlaceText::factsOf($submission->getPayload());
        if (null === $proposal) {
            return;
        }
        $stored = $this->stored((int) $proposal['ref'], true);
        $this->assertHolds($submission, $proposal['facts'], $stored);
        $before = $submission->getPayload()['_before']['facts'] ?? [];
        foreach (array_keys($proposal['facts']) as $field) {
            $entry = \is_array($before) && \is_array($before[$field] ?? null) ? $before[$field] : null;
            if (null === $entry) {
                unset($stored[$field]);
            } else {
                $stored[$field] = $entry;
            }
        }
        $this->save((int) $proposal['ref'], $stored);
    }

    /**
     * Whether a revoke may put this proposal's figures back: they still hold
     * what it set. {@see self::revert()} checks again under a lock.
     *
     * @throws RevokeRefused when a figure was set since
     */
    public function assertRevertable(Submission $submission): void
    {
        $proposal = PlaceText::factsOf($submission->getPayload());
        if (null !== $proposal) {
            $this->assertHolds($submission, $proposal['facts'], $this->stored((int) $proposal['ref'], false));
        }
    }

    /**
     * @param array<string, mixed>      $facts
     * @param array<string, mixed>|null $stored
     *
     * @throws RevokeRefused
     */
    private function assertHolds(Submission $submission, array $facts, ?array $stored): void
    {
        foreach (array_keys($facts) as $field) {
            if ((RegionFacts::entry($stored, $field)['submissionId'] ?? null) !== $submission->getId()) {
                throw new RevokeRefused('moderate.revoke.error.changed_since', [$submission->getTitle().': '.PlaceText::factKey($field)]);
            }
        }
    }

    /**
     * The region's stored figures, the row locked for a write that follows.
     *
     * @return array<string, mixed>|null
     */
    private function stored(int $regionId, bool $lock): ?array
    {
        $row = $this->db->fetchAssociative('SELECT facts FROM region WHERE id = :id'.($lock ? ' FOR UPDATE' : ''), ['id' => $regionId]);
        if (false === $row) {
            throw new \InvalidArgumentException(sprintf('Unknown region %d', $regionId));
        }

        return PlaceText::decode($row['facts']);
    }

    /** @param array<string, mixed>|null $stored */
    private function save(int $regionId, ?array $stored): void
    {
        $this->db->executeStatement(
            'UPDATE region SET facts = :f, updated_at = NOW() WHERE id = :id',
            ['f' => null === $stored || [] === $stored ? null : json_encode($stored, \JSON_THROW_ON_ERROR), 'id' => $regionId],
        );
    }
}
