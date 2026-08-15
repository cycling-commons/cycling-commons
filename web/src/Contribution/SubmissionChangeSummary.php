<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemType;

/**
 * What a rider actually contributed, in words they recognise.
 *
 * A contribution row that says only "Côte de la Redoute — approved" tells the
 * person who wrote it nothing: two edits to the same climb read identically,
 * and neither says what was changed. The curator's desk has had this since it
 * was built (`SubmissionQueue::diffStrings()`, rendered as the `.q-diff`
 * was/now block); the rider's own contributions list and their approval
 * messages did not (owner-reported 2026-08-03).
 *
 * The difference from the desk's version, and the reason this is its own class
 * rather than a shared method: **a curator reads field keys, a rider must not
 * have to.** The desk prints `pumpValve: Presta + Schrader`, because a curator
 * works the catalog every day and the key is the fastest thing to scan. A rider
 * sees "Valve type", the label the form asked them for — resolved through
 * {@see CatalogFormRegistry}, the same registry that rendered the field.
 *
 * Values themselves are formatted by {@see ChangeValue}, shared with the desk,
 * so a climb's redrawn route reads as a length and its endpoints on both.
 *
 * A key with no registry entry keeps the key. That is deliberate: it happens
 * for fields the registry has since dropped, and showing `hairpins` is honest
 * where showing nothing would quietly hide part of what somebody submitted.
 *
 * @api Consumed by ProfileController (the contributions list) and
 *      MessagesController (the card for the submission a message is about).
 */
final readonly class SubmissionChangeSummary
{
    public function __construct(private CatalogFormRegistry $registry)
    {
    }

    /**
     * One row per changed field, in the order the submission recorded them.
     *
     * `was` is null when the field had no previous value — which is the whole
     * "add a missing field" funnel, and the commonest contribution there is.
     * Callers render the strike-through only when there is something to strike
     * out (the same gate the desk's card learned to use).
     *
     * @return list<array{label: string, was: ?string, now: string}>
     */
    public function rows(Submission $submission): array
    {
        $labels = $this->labels($submission->getLetter());
        $rows = [];

        $changes = $submission->getChanges();
        foreach ($changes as $field => $pair) {
            if (!\is_array($pair)) {
                // Defensive: a payload shape that is not {was, now} is not a
                // diff and cannot be rendered as one. Skipping beats guessing.
                continue;
            }
            $was = $pair['was'] ?? null;
            $now = $pair['now'] ?? null;

            $rows[] = [
                'label' => $labels[$field] ?? $field,
                'was' => null === $was || '' === $was ? null : ChangeValue::format((string) $field, $was),
                'now' => ChangeValue::format((string) $field, $now),
            ];
        }

        return $rows;
    }

    /**
     * Field name → the label the contribute form used.
     *
     * Letters with no registry entry (K · routes carries its own proposal
     * shape) simply yield no labels, and every row falls back to its key.
     *
     * @return array<string, string>
     */
    private function labels(string $letter): array
    {
        $type = $this->typeFor($letter);
        if (null === $type) {
            return [];
        }

        $labels = [];
        foreach ($this->registry->for($type)->all() as $field) {
            $labels[$field->name] = $field->label;
        }

        return $labels;
    }

    private function typeFor(string $letter): ?ItemType
    {
        foreach (ItemType::cases() as $case) {
            if ($case->letter() === $letter) {
                return $case;
            }
        }

        return null;
    }
}
