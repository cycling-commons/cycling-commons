<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemType;
use App\Translation\TranslationDiff;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Rider-facing change summary: field labels from the form registry, values via ChangeValue.
 *
 * @api
 */
final readonly class SubmissionChangeSummary
{
    public function __construct(private CatalogFormRegistry $registry, private TranslatorInterface $translator)
    {
    }

    /** From this many words on both sides a value is prose, and its row shows only what changed. */
    private const int PROSE_WORDS = 12;

    /**
     * One row per changed field. `was` is null when the field had no previous value.
     * A prose value that had one also gets `diff`: the changed words with a
     * few unchanged ones around them (TranslationDiff::around()), so one
     * changed word in a long text is not two whole texts to compare.
     *
     * @return list<array{label: string, was: ?string, now: string, diff?: list<array{type: 'eq'|'del'|'ins', text: string}>}>
     */
    public function rows(Submission $submission): array
    {
        $labels = $this->labels($submission->getLetter());
        $rows = [];

        $changes = $submission->getChanges();
        foreach ($changes as $field => $pair) {
            if (!\is_array($pair)) {
                // Skip a payload that is not {was, now}.
                continue;
            }
            $was = $pair['was'] ?? null;
            $now = $pair['now'] ?? null;

            $textLang = PlaceText::langOfKey((string) $field);
            $storyLang = PlaceText::storyLangOfKey((string) $field);
            $row = [
                'label' => $labels[$field] ?? match (true) {
                    // A region's lead is its geography text (§3.1b); a town card's is just its text.
                    null !== $textLang => $this->translator->trans(PlaceText::REGION === ($submission->getPayload()['target'] ?? null) ? 'place_text.geography_change_label' : 'place_text.change_label', ['%lang%' => strtoupper($textLang)]),
                    null !== $storyLang => $this->translator->trans('place_text.story_change_label', ['%lang%' => strtoupper($storyLang)]),
                    default => (string) $field,
                },
                'was' => null === $was || '' === $was ? null : ChangeValue::format((string) $field, $was),
                'now' => ChangeValue::format((string) $field, $now),
            ];
            if (null !== $row['was'] && self::isProse($row['was']) && self::isProse($row['now'])) {
                $row['diff'] = TranslationDiff::around(TranslationDiff::words($row['was'], $row['now']));
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function isProse(string $value): bool
    {
        return \count(preg_split('/\s+/u', trim($value), -1, \PREG_SPLIT_NO_EMPTY) ?: []) >= self::PROSE_WORDS;
    }

    /**
     * Field name → contribute-form label.
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
