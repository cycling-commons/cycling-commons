<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Canonical difficulty `{score:1..5, label}` for every route.
 *
 * @see docs/specs/route-domain.md §9
 *
 * @api
 */
final class DifficultyVocabulary
{
    /** @var array<int, string> */
    public const array LABELS = [1 => 'Easy', 2 => 'Moderate', 3 => 'Challenging', 4 => 'Hard', 5 => 'Very hard'];

    /** @return array{score:int, label:string}|null */
    public static function canonical(mixed $stored): ?array
    {
        if (\is_array($stored) && isset($stored['score']) && \is_int($stored['score'])) {
            $score = max(1, min(5, $stored['score']));

            return ['score' => $score, 'label' => self::LABELS[$score]];
        }
        if (\is_string($stored)) {
            $byLabel = array_search($stored, self::LABELS, true);
            if (false !== $byLabel) {
                return ['score' => $byLabel, 'label' => $stored];
            }
        }

        return null;
    }

    /** @return array<string, string> label => label, for ChoiceType choices */
    public static function choices(): array
    {
        return array_combine(array_values(self::LABELS), array_values(self::LABELS));
    }
}
