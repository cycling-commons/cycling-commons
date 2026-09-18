<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

/**
 * The editorial metadata a recommended route carries: one definition of every
 * field, its vocabulary, and the shape the value is stored in. The rider's
 * proposal form, the curator's edit form on the Routes desk and the map
 * drawer's field schema all read this one set.
 *
 * `canonical()` is the single intake gate. It answers with the value as it
 * belongs in `recommended_route.attributes`, or null when the field carries
 * nothing: an empty pick, a blank note, or a value outside the vocabulary. A
 * null is stored as an absent key, never as a blank, so "nobody has said" and
 * "somebody said nothing" stay the same state.
 *
 * @see docs/specs/route-domain.md §9
 * @see docs/specs/edit-items/R-quality-rides.md
 *
 * @api
 */
final class RouteMetadata
{
    /**
     * The route's own name (`recommended_route.name`), not an attribute. Both
     * forms post it under this key; its history rows are filed under `name`.
     */
    public const string NAME_FIELD = 'rName';

    /** Attribute keys the forms write, in the order both forms show them. */
    public const array ATTRIBUTE_FIELDS = [
        'difficulty', 'season', 'dominantSurface', 'note', 'bikeTypes', 'gradientLimited', 'bestDirection',
    ];

    /**
     * Stored `gradientLimited` value => its label key.
     *
     * @var array<string, string>
     */
    public const array GRADIENT_LABELS = [
        'No' => 'propose_route.gradient_none',
        '≤6%' => 'propose_route.gradient_6',
        '≤9%' => 'propose_route.gradient_9',
    ];

    /** Stored `bestDirection` values; each value is its own label msgid. */
    public const array DIRECTIONS = ['Clockwise', 'Counter-clockwise', 'Either'];

    /**
     * Field key => the label every surface shows it under: the proposal form,
     * the curator's desk form, the drawer's correction box and the desk's
     * was/now card all read this one map.
     *
     * @var array<string, string>
     */
    public const array LABELS = [
        self::NAME_FIELD => 'propose_route.name_label',
        'difficulty' => 'propose_route.difficulty_label',
        'season' => 'propose_route.season_label',
        'dominantSurface' => 'propose_route.surface_label',
        'note' => 'propose_route.note_label',
        'bikeTypes' => 'propose_route.bike_types_label',
        'gradientLimited' => 'propose_route.gradient_label',
        'bestDirection' => 'Best direction',
    ];

    /** Every field a rider may ask to have changed, in the forms' order. */
    public const array EDITABLE_FIELDS = [self::NAME_FIELD, ...self::ATTRIBUTE_FIELDS];

    /**
     * How a field is asked for: the widget kind the drawer's correction box
     * draws and the desk form already uses.
     */
    public static function kindFor(string $field): string
    {
        return match ($field) {
            self::NAME_FIELD => 'text',
            'note' => 'textarea',
            'season', 'bikeTypes' => 'multiselect',
            'difficulty', 'dominantSurface', 'gradientLimited', 'bestDirection' => 'select',
            default => throw new \InvalidArgumentException(sprintf('"%s" is not a route metadata field.', $field)),
        };
    }

    /**
     * A select or multi-select field's options: stored value => label msgid.
     * Empty for the text fields, which have no vocabulary.
     *
     * @return array<string, string>
     */
    public static function choicesFor(string $field): array
    {
        return match ($field) {
            'difficulty' => array_combine(array_values(DifficultyVocabulary::LABELS), array_values(DifficultyVocabulary::LABELS)),
            'season' => array_combine(self::seasons(), array_map(
                static fn (string $s): string => Season::from(strtolower($s))->labelKey(),
                self::seasons(),
            )),
            'dominantSurface' => array_combine(SurfaceVocabulary::DECLARABLE, SurfaceVocabulary::DECLARABLE),
            'bikeTypes' => array_combine(BikeType::values(), array_map(
                static fn (string $b): string => BikeType::from($b)->labelKey(),
                BikeType::values(),
            )),
            'gradientLimited' => self::GRADIENT_LABELS,
            'bestDirection' => array_combine(self::DIRECTIONS, self::DIRECTIONS),
            default => [],
        };
    }

    /** Longest note a route carries, in characters. */
    public const int NOTE_MAX = 2000;

    /** Longest route name, in characters. */
    public const int NAME_MAX = 200;

    /**
     * Stored `season` values: the Season cases, capitalized as they persist.
     *
     * @return list<string>
     */
    public static function seasons(): array
    {
        return array_map(static fn (Season $s): string => ucfirst($s->value), Season::cases());
    }

    /**
     * The value as it belongs in `attributes`, or null when the field carries
     * nothing.
     *
     * @throws \InvalidArgumentException the key is not one of ATTRIBUTE_FIELDS
     */
    public static function canonical(string $field, mixed $raw): mixed
    {
        return match ($field) {
            'difficulty' => DifficultyVocabulary::canonical($raw),
            'season' => self::emptyToNull(self::pick($raw, self::seasons())),
            'dominantSurface' => self::one($raw, SurfaceVocabulary::DECLARABLE),
            'note' => self::text($raw),
            'bikeTypes' => self::emptyToNull(BikeTypeVocabulary::normalize($raw)),
            'gradientLimited' => self::one($raw, array_keys(self::GRADIENT_LABELS)),
            'bestDirection' => self::one($raw, self::DIRECTIONS),
            default => throw new \InvalidArgumentException(sprintf('"%s" is not a route metadata field.', $field)),
        };
    }

    /**
     * The attribute fields this route carries nothing for, in the forms' order.
     * A value the vocabulary no longer knows counts as nothing, the same way
     * the forms prefill it as empty, so the desk never reports a field as
     * filled that its own form would show blank.
     *
     * The name is not among them: a route always has one.
     *
     * @param array<string, mixed> $attributes
     *
     * @return list<string>
     */
    public static function unsetFields(array $attributes): array
    {
        $missing = [];
        foreach (self::ATTRIBUTE_FIELDS as $field) {
            if (null === self::canonical($field, $attributes[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Every attribute a form prefills from, keyed by field: the stored value in
     * the shape its widget speaks. A field holding nothing, or holding a value
     * the vocabulary no longer knows, prefills as null and shows empty.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    public static function formValues(array $attributes): array
    {
        $out = [];
        foreach (self::ATTRIBUTE_FIELDS as $field) {
            $stored = $attributes[$field] ?? null;
            // The difficulty widget speaks the label; the store keeps {score,label}.
            $out[$field] = 'difficulty' === $field
                ? DifficultyVocabulary::canonical($stored)['label'] ?? null
                : self::canonical($field, $stored);
        }

        return $out;
    }

    /**
     * What a submitted form would change about a route, as
     * `{field: {was, now}}` against the route's values right now. Only fields
     * whose canonical value differs appear; an empty field against an unset
     * one records no phantom change, and clearing a set field records a
     * removal (`was` → null). Same contract as `Submission::changes`
     * (moderation-and-contribution.md §3.2), on the route's own tables.
     *
     * @param array<string, mixed> $submitted  form data keyed as the forms post it
     * @param array<string, mixed> $attributes the route's current attributes
     *
     * @return array<string, array{was: mixed, now: mixed}>
     */
    public static function changesAgainst(array $submitted, array $attributes, string $currentName): array
    {
        $changes = [];
        if (\array_key_exists(self::NAME_FIELD, $submitted)) {
            $name = \is_string($submitted[self::NAME_FIELD]) ? trim($submitted[self::NAME_FIELD]) : '';
            if ('' !== $name && $name !== $currentName) {
                $changes[self::NAME_FIELD] = ['was' => $currentName, 'now' => $name];
            }
        }
        foreach (self::ATTRIBUTE_FIELDS as $field) {
            if (!\array_key_exists($field, $submitted)) {
                continue;
            }
            $was = $attributes[$field] ?? null;
            $now = self::canonical($field, $submitted[$field]);
            if (!self::isSame($was, $now)) {
                $changes[$field] = ['was' => $was, 'now' => $now];
            }
        }

        return $changes;
    }

    /**
     * The `now` side of a changes map, keyed as {@see \App\Moderation\RouteModerationService::editMetadata()}
     * takes it, so an approved correction applies through the one intake gate.
     *
     * @param array<string, array{was: mixed, now: mixed}> $changes
     *
     * @return array<string, mixed>
     */
    public static function applyable(array $changes): array
    {
        $out = [];
        foreach ($changes as $field => $pair) {
            // A cleared field carries `now` null, which the intake gate reads
            // as "unset it"; a name change never carries null.
            $out[$field] = $pair['now'] ?? null;
        }

        return $out;
    }

    /**
     * Whether two stored values say the same thing. Arrays compare by content:
     * `{label,score}` and `{score,label}` are one difficulty, and a field that
     * round-trips through the form unchanged writes no history row.
     */
    public static function isSame(mixed $a, mixed $b): bool
    {
        if (\is_array($a) && \is_array($b)) {
            return $a == $b;
        }

        return $a === $b;
    }

    /**
     * @param list<string> $vocabulary
     */
    private static function one(mixed $raw, array $vocabulary): ?string
    {
        $value = self::text($raw);

        return null !== $value && \in_array($value, $vocabulary, true) ? $value : null;
    }

    /**
     * @param list<string> $vocabulary
     *
     * @return list<string> the vocabulary members present in $raw, deduplicated
     */
    private static function pick(mixed $raw, array $vocabulary): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $value) {
            if (\is_string($value) && \in_array($value, $vocabulary, true) && !\in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>|null
     */
    private static function emptyToNull(array $values): ?array
    {
        return [] !== $values ? $values : null;
    }

    private static function text(mixed $raw): ?string
    {
        if (!\is_string($raw)) {
            return null;
        }
        $value = trim($raw);

        return '' !== $value ? $value : null;
    }
}
