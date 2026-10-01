<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Provider;

use App\Catalog\CatalogField;
use App\Catalog\CatalogFormRegistry;
use App\Catalog\FieldKind;
use App\Catalog\ItemType;
use App\Provider\Entity\DataProvider;
use App\Provider\Exception\ProviderRuleException;

/**
 * A provider's defaults: the answer a harvest gives a field the provider's
 * own data leaves empty.
 *
 * Some facts are true of every record in a register and so are not a column
 * in it: every Dutch RIVM tap is free, bottle-friendly and shut against frost.
 * The registry row says them once, per letter, in the words of that letter's
 * edit form, and the harvest fills them in where nothing else has spoken.
 *
 * Three rules, all of them here so the desk, the registry and the harvest
 * cannot disagree:
 *
 * 1. **A default is a choice a rider could make.** Only a single-choice field
 *    of the letter's form can carry one, and only with one of that field's own
 *    choices. Free text, links and multi-choice fields are refused: a default
 *    note or website would be the same sentence on thousands of places, and
 *    no curator can check that it is true of each.
 * 2. **A default never claims somebody looked.** `condition` ("Still as
 *    mapped?") and a hazard's `stillPresent` are observations, so only a
 *    rider standing there answers them (catalog-data-model.md §7).
 * 3. **A default only fills a gap.** It never replaces a value the provider's
 *    own data carries (the field map wins), never a value already stored on
 *    the row, and never a field a person changed, even to empty.
 *
 * @see docs/specs/data-provider-hierarchy.md §5.2
 *
 * @api
 */
final readonly class ProviderDefaults
{
    /** Observations: no provider can answer these for a rider. */
    public const array NEVER_DEFAULTED = ['condition', 'stillPresent'];

    public function __construct(
        private CatalogFormRegistry $forms,
    ) {
    }

    /**
     * The fields of a letter's edit form that may carry a default, in form
     * order. Empty for a letter with no place form.
     *
     * @return list<CatalogField>
     */
    public function fieldsFor(string $letter): array
    {
        $type = ItemType::fromLetter($letter);
        if (null === $type || ItemType::QualityRides === $type) {
            return [];
        }

        return array_values(array_filter(
            $this->forms->for($type)->all(),
            static fn (CatalogField $f): bool => FieldKind::Select === $f->kind
                && !$f->derived
                && !\in_array($f->name, self::NEVER_DEFAULTED, true),
        ));
    }

    /**
     * A desk submission as the registry stores it: `{letter: {field: value}}`,
     * with "(no default)" dropped and every rule checked.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, array<string, string>>
     *
     * @throws ProviderRuleException
     */
    public function normalise(DataProvider $provider, array $raw): array
    {
        $out = [];
        foreach ($raw as $letter => $fields) {
            $letter = (string) $letter;
            if (!\is_array($fields)) {
                throw new ProviderRuleException('provider.error.default_shape');
            }
            if (!\in_array($letter, $provider->getLetters(), true)) {
                // A default for a letter this dataset does not fill would
                // never be read, and would read as a promise that it is.
                throw new ProviderRuleException('provider.error.default_letter');
            }
            $allowed = [];
            foreach ($this->fieldsFor($letter) as $field) {
                $allowed[$field->name] = $field->choices;
            }
            foreach ($fields as $name => $value) {
                $name = (string) $name;
                if (null === $value || '' === $value) {
                    continue;   // "(no default)"
                }
                if (\in_array($name, self::NEVER_DEFAULTED, true)) {
                    throw new ProviderRuleException('provider.error.default_condition');
                }
                if (!\array_key_exists($name, $allowed)) {
                    throw new ProviderRuleException('provider.error.default_field');
                }
                if (!\is_string($value) || !\in_array($value, $allowed[$name], true)) {
                    throw new ProviderRuleException('provider.error.default_value');
                }
                $out[$letter][$name] = $value;
            }
        }
        ksort($out);
        foreach ($out as &$fields) {
            ksort($fields);
        }

        return $out;
    }

    /**
     * Fills the gaps a harvested record leaves, and nothing else.
     *
     * A key counts as a gap when it is absent, null or an empty string after
     * the field map and the merge with what the row already holds. A field a
     * person changed is never a gap, even when they emptied it.
     *
     * @param array<string, mixed>                 $attributes the record after the field map (and, on refresh, the merge)
     * @param array<string, array<string, string>> $defaults   the provider's defaults
     * @param list<string>                         $touched    fields a person changed on the row
     *
     * @return array<string, mixed>
     */
    public static function fill(array $attributes, string $letter, array $defaults, array $touched = []): array
    {
        foreach ($defaults[$letter] ?? [] as $name => $value) {
            if (\in_array($name, self::NEVER_DEFAULTED, true) || \in_array($name, $touched, true)) {
                continue;
            }
            $have = $attributes[$name] ?? null;
            if (null === $have || '' === $have) {
                $attributes[$name] = $value;
            }
        }

        return $attributes;
    }
}
