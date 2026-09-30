<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemType;
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

    /**
     * One row per changed field. `was` is null when the field had no previous value.
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
                // Skip a payload that is not {was, now}.
                continue;
            }
            $was = $pair['was'] ?? null;
            $now = $pair['now'] ?? null;

            $textLang = PlaceText::langOfKey((string) $field);
            $rows[] = [
                'label' => $labels[$field] ?? (null !== $textLang
                    ? $this->translator->trans('place_text.change_label', ['%lang%' => strtoupper($textLang)])
                    : $field),
                'was' => null === $was || '' === $was ? null : ChangeValue::format((string) $field, $was),
                'now' => ChangeValue::format((string) $field, $now),
            ];
        }

        return $rows;
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
