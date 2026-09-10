<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

/**
 * What a contact message is about.
 *
 * Two of these carry a legal clock and are therefore not just labels:
 * {@see self::Privacy} starts the one-month GDPR response window the privacy
 * page promises, and {@see self::Report} is the general
 * notice-and-action entry point the Digital Services Act, Article 16, requires
 * a hosting provider to offer. Both route to a curator, not to a general
 * mailbox, and both are shown ahead of the rest on the form.
 *
 * @see docs/specs/contact-and-support.md §4
 *
 * @api
 */
enum ContactTopic: string
{
    /** Anything at all. The default. */
    case Question = 'question';
    /** A GDPR request: access, correction, deletion, portability, objection. */
    case Privacy = 'privacy';
    /** Content on the site that should not be there. */
    case Report = 'report';
    /** "That photo is mine and I did not licence it." */
    case Copyright = 'copyright';
    /** Something on the site is unusable with a screen reader, a keyboard, or at a readable size. */
    case Accessibility = 'accessibility';
    /** Data, funding, mapping, a partnership, a country. */
    case Partnership = 'partnership';
    /** Journalists. */
    case Press = 'press';

    /** @return list<self> form order */
    public static function all(): array
    {
        return [
            self::Question,
            self::Privacy,
            self::Report,
            self::Copyright,
            self::Accessibility,
            self::Partnership,
            self::Press,
        ];
    }

    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? self::Question;
    }

    /**
     * Is there a deadline on answering this one?
     *
     * GDPR Articles 15 to 22 give a data subject one month. DSA Article 16
     * gives no fixed number but requires timely, diligent and non-arbitrary
     * handling; we hold ourselves to the same month, and say so on the page.
     */
    public function isOnAClock(): bool
    {
        return match ($this) {
            self::Privacy, self::Report, self::Copyright, self::Accessibility => true,
            default => false,
        };
    }

    /** Days we promise ourselves, for the desk to sort and colour by. */
    public function dueDays(): ?int
    {
        return $this->isOnAClock() ? 30 : null;
    }
}
