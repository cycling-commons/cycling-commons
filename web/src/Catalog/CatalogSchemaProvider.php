<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Serialises a type's *display* fields for the map drawer, straight from the
 * same {@see CatalogFormRegistry} that builds the improve form — one source of
 * truth, so the drawer can never drift from the form. Labels are localised
 * (the `messages` domain, source-string keys, exactly as ImproveType renders
 * them) so no English label is baked into map.js.
 *
 * @api Injected into MapController; emitted as window.CC_FIELD_SCHEMA.
 */
final class CatalogSchemaProvider
{
    public function __construct(
        private readonly CatalogFormRegistry $registry,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @return list<array{key: string, label: string, kind: string}> */
    public function displayFields(ItemType $type): array
    {
        $out = [];
        foreach ($this->registry->for($type)->all() as $field) {
            if (!$field->display) {
                continue;
            }
            $out[] = [
                'key' => $field->name,
                'label' => $this->translator->trans($field->label),
                'kind' => $this->renderKind($field),
            ];
        }

        return $out;
    }

    /** @return array<string, list<array{key: string, label: string, kind: string}>> */
    public function all(): array
    {
        $out = [];
        foreach (ItemType::cases() as $type) {
            $out[$type->letter()] = $this->displayFields($type);
        }

        return $out;
    }

    /**
     * A drawer render-hint, not just the raw form input kind: a select whose
     * choices are exactly the 1-5 rating scale renders as star glyphs, not a
     * dropdown.
     */
    private function renderKind(CatalogField $field): string
    {
        if (FieldKind::Select === $field->kind && $field->choices === ['1', '2', '3', '4', '5']) {
            return 'rating';
        }

        return $field->kind->value;
    }
}
