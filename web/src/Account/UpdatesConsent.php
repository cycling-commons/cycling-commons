<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Account;

/**
 * Consent to be told when a release ships.
 *
 * A release list, not a marketing list, and the difference is not a slogan: it
 * bounds what may ever be sent to it. If something is not "the software
 * changed", it does not go here, and the copy the rider agreed to says so in as
 * many words.
 *
 * The shape follows {@see \App\Media\MediaConsent}: a `kind`, a `version`, and
 * the key of the text somebody actually read. Bumping `VERSION` when the
 * wording changes materially is what keeps an old record honest, because the
 * record then points at what was agreed rather than at what the page says now.
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
    public const string VERSION = 'v1';

    /** The sentence beside the toggle: what a rider is agreeing to. */
    public const string TEXT_KEY = 'settings.updates_consent';

    /**
     * A stable fingerprint of the agreed wording.
     *
     * The key and version rather than the rendered sentence, because the
     * sentence differs per locale and the same consent must hash the same
     * whether it was given in Dutch or Spanish.
     */
    public static function hash(): string
    {
        return hash('sha256', self::KIND.'|'.self::VERSION.'|'.self::TEXT_KEY);
    }
}
