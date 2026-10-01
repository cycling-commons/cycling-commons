<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

/**
 * Consent to be kept up to date about Cycling Commons.
 *
 * News about the project itself, new versions first, never anything from or
 * for somebody else. v1 was release notes only; v2 ("Keep me up to date",
 * owner 2026-10-01) is the project's own news, as often as the rider's
 * cadence allows.
 *
 * The shape follows {@see \App\Media\MediaConsent}: a `kind`, a `version`, and
 * the key of the text somebody actually read. Bumping `VERSION` when the
 * wording changes materially is what keeps an old record honest, because the
 * record then points at what was agreed rather than at what the page says now.
 *
 * **v2 carries the cadence.** From v2 the rider agrees to the switch's sentence
 * plus one of two ceilings ({@see UpdatesCadence}), so the recorded version is
 * `v2-big` or `v2-every` and the hash covers both sentences' keys. A v1 record
 * stays exactly as written: its version is `v1`, its text is still in the
 * catalogue under {@see self::V1_TEXT_KEY}, and "a few times a year" is what
 * `UpdatesCadence::Big` honours.
 *
 * **No double opt-in here, deliberately.** The double confirmation exists to
 * prove an address belongs to the person who typed it. This toggle only ever
 * appears to a signed-in rider whose address was already verified at
 * registration, so a second confirmation email would prove nothing and train
 * people to click links in mail from us. The standalone signup for people
 * without an account is a different thing and does need it; it is not built
 * (`docs/TODO.md` 10).
 *
 * @see docs/specs/roadmap-and-changelog.md §4
 *
 * @api
 */
final class UpdatesConsent
{
    public const string KIND = 'release-updates';

    /** Bump when the promise below changes, not when its translation does. */
    public const string VERSION = 'v2';

    /** The sentence beside the toggle: what a rider is agreeing to. */
    public const string TEXT_KEY = 'settings.updates_consent_v2';

    /** The v1 sentence, kept so a v1 record still points at what was agreed. */
    public const string V1_TEXT_KEY = 'settings.updates_consent';

    /** The version string on a record: the wording version and the cadence. */
    public static function version(UpdatesCadence $cadence): string
    {
        return self::VERSION.'-'.$cadence->value;
    }

    /**
     * A stable fingerprint of the agreed wording.
     *
     * The keys and version rather than the rendered sentences, because the
     * sentences differ per locale and the same consent must hash the same
     * whether it was given in Dutch or Spanish.
     */
    public static function hash(UpdatesCadence $cadence): string
    {
        return hash('sha256', self::KIND.'|'.self::version($cadence).'|'.self::TEXT_KEY.'|'.$cadence->labelKey());
    }

    /**
     * The catalogue keys of the sentences a record's version stands for, so
     * any record, old or new, can be shown with the words that were agreed.
     *
     * @return list<string> empty for a version this class never wrote
     */
    public static function textKeys(string $recordedVersion): array
    {
        if ('v1' === $recordedVersion) {
            return [self::V1_TEXT_KEY];
        }
        foreach (UpdatesCadence::cases() as $cadence) {
            if (self::version($cadence) === $recordedVersion) {
                return [self::TEXT_KEY, $cadence->labelKey()];
            }
        }

        return [];
    }
}
