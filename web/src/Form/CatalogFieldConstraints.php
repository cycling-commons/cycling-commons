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
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\Url;

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
            $constraints[] = new NotBlank(message: 'contribute.error.field_required');
        }
        // Select and MultiSelect values are constrained by ChoiceType itself
        // (and MultiSelect's value is a list<string>, not a scalar — the
        // string-shaped Length/NoSuspiciousCharacters/Cf-regex checks below
        // don't apply to either).
        if (!\in_array($field->kind, [FieldKind::Select, FieldKind::MultiSelect], true)) {
            $constraints[] = new Length(max: $field->maxLength, maxMessage: 'contribute.error.field_too_long');
            $constraints[] = self::noSuspiciousCharacters();
            // NoSuspiciousCharacters' CHECK_INVISIBLE (ICU Spoofchecker) only fires on
            // *repeated identical* nonspacing combining marks, not a lone Cf format
            // character (e.g. U+200B ZERO WIDTH SPACE); soft hyphen U+00AD is also Cf
            // and thus rejected too — acceptable for the en/fr/nl/de locale set.
            $constraints[] = new Regex(pattern: '/\p{Cf}/u', match: false, message: 'contribute.error.invisible_characters');
        }

        // A url-kind field (a stay's `web` / `bookingLink`) is text-like — it
        // keeps Length + suspicious-character guards above — but its value is
        // interpolated into an `<a href>` on the public map, so it must ALSO be
        // a real http(s) URL. Restricting protocols to http/https is what stops
        // a `javascript:`/`data:` payload from ever persisting (critical #3).
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
     * NoSuspiciousCharacters over {@see LOCALES} with EVERY built-in message
     * repointed at our own catalogue key. The constraint has four distinct default
     * messages (restriction-level / invisible / mixed-numbers / hidden-overlay),
     * all English; since framework.validation.translation_domain is `messages`
     * (not `validators`), leaving any on its default would render English to
     * fr/nl/de users. One key covers them all — the exact sub-check is an
     * implementation detail the rider doesn't need spelled out.
     *
     * @api Shared by ProposeRouteType and AddClimbType.
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
