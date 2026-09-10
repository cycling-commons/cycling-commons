<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Translation;

use App\Media\MediaConsent;

/**
 * Catalogue keys the overlay must never touch.
 *
 * Both are consent contracts: the exact words a rider agreed to, hashed into
 * the consent ledger under a VERSION. Standing consent is keyed on that
 * VERSION alone ({@see TranslationConsentService::current()},
 * {@see \App\Media\ConsentService::assertValid()}), so a contract that could
 * be reworded through an approved overlay would leave every earlier record
 * covering words its rider never saw, with nothing asking them again. Legal
 * text changes by a VERSION bump in code, and nowhere else.
 *
 * Held in three places, each on its own: {@see ProposalService::submit()}
 * refuses a proposal, {@see CatalogueBrowser} does not list the key, and
 * {@see OverlayCatalogueLoader} ignores any row that got there anyway.
 *
 * @see docs/specs/translations.md §4
 *
 * @api
 */
final class ProtectedKeys
{
    /** @var list<string> */
    public const array KEYS = [
        TranslationConsent::TEXT_KEY,
        MediaConsent::TEXT_KEY,
    ];

    public static function isProtected(string $messageKey): bool
    {
        return \in_array($messageKey, self::KEYS, true);
    }
}
