<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Display fields for the map drawer, from the same {@see CatalogFormRegistry} as the improve form.
 *
 * @api
 */
final class CatalogSchemaProvider
{
    public function __construct(
        private readonly CatalogFormRegistry $registry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @return list<array{key: string, label: string, kind: string, choices?: array<string, string>}> */
    public function displayFields(ItemType $type): array
    {
        $out = [];
        foreach ($this->registry->for($type)->all() as $field) {
            if (!$field->display) {
                continue;
            }
            $entry = [
                'key' => $field->name,
                'label' => $this->translator->trans($field->label),
                'kind' => $this->renderKind($field),
            ];
            // Stored value stays canonical English; labels localize. Ratings have no labels.
            if ([] !== $field->choices && 'rating' !== $entry['kind']) {
                $choices = [];
                foreach ($field->choices as $choice) {
                    // A keyed select stores a key and shows its label (CatalogField::selectKeyed()).
                    $choices[$choice] = $this->translator->trans($field->choiceLabels[$choice] ?? $choice);
                }
                $entry['choices'] = $choices;
            }
            $out[] = $entry;
        }

        return $out;
    }

    /** @return array<string, list<array{key: string, label: string, kind: string, choices?: array<string, string>}>> */
    public function all(): array
    {
        $out = [];
        foreach (ItemType::cases() as $type) {
            $out[$type->letter()] = $this->displayFields($type);
        }

        return $out;
    }

    /** Drawer hint: a 1–5 select renders as stars, not a dropdown. */
    private function renderKind(CatalogField $field): string
    {
        if (FieldKind::Select === $field->kind && $field->choices === ['1', '2', '3', '4', '5']) {
            return 'rating';
        }

        return $field->kind->value;
    }
}
