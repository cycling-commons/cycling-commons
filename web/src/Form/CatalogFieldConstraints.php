<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Form;

use App\Catalog\CatalogField;
use App\Catalog\FieldKind;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NoSuspiciousCharacters;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Registry descriptor → validator constraints, in ONE place (spec §6.1):
 * every text-like catalog field gets Length + NoSuspiciousCharacters;
 * required fields get NotBlank. Selects are constrained by ChoiceType itself.
 *
 * @api Used by ImproveType (and any future registry-driven form).
 */
final class CatalogFieldConstraints
{
    public const array LOCALES = ['en', 'fr', 'nl', 'de'];

    /** @return list<Constraint> */
    public static function for(CatalogField $field): array
    {
        $constraints = [];
        if ($field->required) {
            $constraints[] = new NotBlank();
        }
        if (FieldKind::Select !== $field->kind) {
            $constraints[] = new Length(max: $field->maxLength);
            $constraints[] = new NoSuspiciousCharacters(locales: self::LOCALES);
        }

        return $constraints;
    }
}
