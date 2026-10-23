<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemType;
use App\Catalog\RegionFacts;
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
        $target = $submission->getPayload()['target'] ?? null;

        return $this->rowsFor($submission->getLetter(), $submission->getChanges(), \is_string($target) ? $target : null);
    }

    /**
     * The same rows from a stored changes blob, for a desk row that carries
     * no entity. `$target` is a Text submission's town or region.
     *
     * @param array<array-key, mixed> $changes
     *
     * @return list<array{label: string, was: ?string, now: string, diff?: list<array{type: 'eq'|'del'|'ins', text: string}>}>
     */
    public function rowsFor(string $letter, array $changes, ?string $target): array
    {
        $labels = $this->labels($letter);
        $rows = [];
        // A region's figures in the page's order, whatever order JSONB keeps
        // them in; the sort is stable, so every other row keeps its place.
        uksort($changes, static fn (int|string $a, int|string $b): int => self::factOrder((string) $a) <=> self::factOrder((string) $b));

        foreach ($changes as $field => $pair) {
            if (!\is_array($pair)) {
                // Skip a payload that is not {was, now}.
                continue;
            }
            $was = $pair['was'] ?? null;
            $now = $pair['now'] ?? null;

            // A region's figure (§3.1g), in the words and units the page shows it in.
            $fact = PlaceText::factOfKey((string) $field);
            if (null !== $fact) {
                $rows[] = [
                    'label' => $this->translator->trans('place_text.fact_label_'.$fact),
                    'was' => $this->factValue($fact, $was),
                    'now' => $this->factValue($fact, $now) ?? '',
                ];
                continue;
            }

            $textLang = PlaceText::langOfKey((string) $field);
            $storyLang = PlaceText::storyLangOfKey((string) $field);
            $row = [
                'label' => $labels[$field] ?? match (true) {
                    // A region's lead is its geography text (§3.1b); a town card's is just its text.
                    null !== $textLang => $this->translator->trans(PlaceText::REGION === $target ? 'place_text.geography_change_label' : 'place_text.change_label', ['%lang%' => strtoupper($textLang)]),
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

    /** Where a figure's row goes: its place in RegionFacts::FIELDS; any other row after them. */
    private static function factOrder(string $key): int
    {
        $fact = PlaceText::factOfKey($key);
        $at = null === $fact ? false : array_search($fact, RegionFacts::FIELDS, true);

        return false === $at ? \count(RegionFacts::FIELDS) : (int) $at;
    }

    /** One figure's value as the region page shows it: "2,680 km²", "494,000 (2024)", "Hilly". Null for none. */
    private function factValue(string $fact, mixed $value): ?string
    {
        return match (true) {
            RegionFacts::AREA === $fact && \is_int($value) => number_format($value, 0, '.', ',').' km²',
            RegionFacts::POPULATION === $fact && \is_array($value) && \is_int($value['value'] ?? null) => number_format($value['value'], 0, '.', ',').(\is_int($value['year'] ?? null) ? ' ('.$value['year'].')' : ''),
            RegionFacts::RIDE === $fact && \is_string($value) && \in_array($value, RegionFacts::RIDES, true) => $this->translator->trans('region.ride_'.$value),
            default => null,
        };
    }

    private static function isProse(string $value): bool
    {
        return \count(preg_split('/\s+/u', trim($value), -1, \PREG_SPLIT_NO_EMPTY) ?: []) >= self::PROSE_WORDS;
    }

    /**
     * Field name → contribute-form label, in the reader's language.
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
            $labels[$field->name] = $this->translator->trans($field->label);
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
