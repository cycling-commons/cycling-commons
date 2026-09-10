<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Form;

use App\Catalog\CatalogField;
use App\Catalog\FieldKind;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NoSuspiciousCharacters;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

/**
 * Registry field → validator constraints (Length, NoSuspiciousCharacters, NotBlank).
 *
 * @see docs/specs/moderation-and-contribution.md §2
 *
 * @api
 */
final class CatalogFieldConstraints
{
    public const array LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

    /** @return list<Constraint> */
    public static function for(CatalogField $field): array
    {
        $constraints = [];
        if ($field->required) {
            $constraints[] = new NotBlank(message: 'contribute.error.field_required');
        }
        if (!\in_array($field->kind, [FieldKind::Select, FieldKind::MultiSelect], true)) {
            $constraints[] = new Length(max: $field->maxLength, maxMessage: 'contribute.error.field_too_long');
            $constraints[] = self::noSuspiciousCharacters();
            // docs/specs/moderation-and-contribution.md §2 — CHECK_INVISIBLE misses a lone Cf (U+200B).
            $constraints[] = new Regex(pattern: '/\p{Cf}/u', match: false, message: 'contribute.error.invisible_characters');
        }

        // docs/specs/catalog-data-model.md §7 — http(s) only; blocks javascript:/data: in a public href.
        if (FieldKind::Url === $field->kind) {
            $constraints[] = new Url(
                message: 'contribute.error.invalid_url',
                protocols: ['http', 'https'],
                requireTld: true,
                tldMessage: 'contribute.error.invalid_url',
            );
        }

        return $constraints;
    }

    /**
     * NoSuspiciousCharacters over {@see LOCALES}; messages go to one catalogue key.
     *
     * @api
     */
    public static function noSuspiciousCharacters(): NoSuspiciousCharacters
    {
        return new NoSuspiciousCharacters(
            locales: self::LOCALES,
            restrictionLevelMessage: 'contribute.error.suspicious_characters',
            invisibleMessage: 'contribute.error.suspicious_characters',
            mixedNumbersMessage: 'contribute.error.suspicious_characters',
            hiddenOverlayMessage: 'contribute.error.suspicious_characters',
        );
    }
}
