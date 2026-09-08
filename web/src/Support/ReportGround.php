<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Support;

/**
 * Why somebody is reporting it.
 *
 * The same five grounds `/terms` §12 already publishes as "what gets taken
 * down", plus the sixth added on 2026-08-28 for generated images. They are the
 * same list on purpose: a reporter should be choosing from the rules we
 * actually apply, and the statement of reasons DSA Article 17 requires has to
 * name a ground the author can go and read.
 *
 * @see docs/specs/content-reports.md §2
 *
 * @api
 */
enum ReportGround: string
{
    case Unlawful = 'unlawful';
    case PersonalData = 'personal_data';
    case Untrue = 'untrue';
    case Abuse = 'abuse';
    case Advertising = 'advertising';
    case Generated = 'generated';
    /**
     * Intimate imagery, or a child. The one ground that hides the picture
     * before a curator has seen it (@see self::autoWithholds()). Carried over
     * whole from `MediaTakedownCategory` on 2026-08-30.
     */
    case IntimateOrChild = 'intimate_or_child';
    /** "It shows private property or something that should not be public." */
    case PrivateProperty = 'private_property';
    /**
     * "That is my work." Backlog item 6: photos arrive under CC BY-SA 4.0 on
     * the contributor's word, and when that word is wrong the rights holder had
     * nowhere to go but the general address. Not image only: copyrighted prose
     * can be pasted into a route description as easily as a photograph can be
     * uploaded.
     */
    case Copyright = 'copyright';

    /** The catalogue key. Mirrors the `terms.mod_std*` line it comes from. */
    public function label(): string
    {
        return 'report.ground.'.$this->value;
    }

    /**
     * Grounds that are a legal claim rather than a quality judgement.
     *
     * These get a curator's attention first, and their decisions are the ones
     * most likely to be appealed, so the desk sorts on it.
     */
    public function isLegal(): bool
    {
        return match ($this) {
            self::Unlawful, self::PersonalData, self::IntimateOrChild, self::Copyright => true,
            // PrivateProperty is deliberately NOT here. Photographing a house
            // from a public road is lawful in most of Europe, so "that is my
            // drive" is a request we usually grant out of courtesy rather than
            // a claim with a statutory clock. Treating it as legal would sort
            // every garden above an actual defamation claim.
            default => false,
        };
    }

    /**
     * Does a report on this ground hide the thing on the spot?
     *
     * Moved off `MediaTakedownCategory` on 2026-08-30. It is only ever true
     * with a target that can be withheld at all, which the caller checks with
     * `ReportTarget::canAutoWithhold()`; the circuit breaker in front of it is
     * unchanged.
     */
    public function autoWithholds(): bool
    {
        return self::IntimateOrChild === $this;
    }

    /**
     * Is an address required, rather than merely invited?
     *
     * Required for every ground but one (owner, 2026-08-30: "I still say email
     * required unless legal not allowed in case of child"). DSA Article 16(2)(c)
     * lists the reporter's name and email as elements a notice should carry, so
     * asking for it is what the Article describes, not a barrier against it.
     * The thing Article 16 forbids is requiring an ACCOUNT, and this form still
     * requires none.
     *
     * The exception is the same one the Article writes down: a notice about
     * information involving the offences in Articles 3 to 7 of Directive
     * 2011/93/EU, which is what `IntimateOrChild` covers. There, an address may
     * not be demanded, so it is not.
     */
    public function requiresContact(): bool
    {
        return self::IntimateOrChild !== $this;
    }

    /** Does this ground ask for the extra rights-holder fields? */
    public function needsOwnershipProof(): bool
    {
        return self::Copyright === $this;
    }

    /**
     * The legal-claim grounds as a list, for the desk's IN() sort.
     *
     * @return list<self>
     */
    public static function legal(): array
    {
        return array_values(array_filter(self::all(), static fn (self $g): bool => $g->isLegal()));
    }

    /**
     * What the desk puts first: every legal claim, and abuse (owner
     * 2026-09-08: "both abuse and legal should float to the top"). Abuse is
     * not a legal claim, but a person is being hurt while it waits.
     */
    public function isUrgent(): bool
    {
        return $this->isLegal() || self::Abuse === $this;
    }

    /** @return list<self> */
    public static function urgent(): array
    {
        return array_values(array_filter(self::all(), static fn (self $g): bool => $g->isUrgent()));
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * The grounds a picture can be reported on, but nothing else can.
     *
     * `Generated` is about an image presented as a real photograph (terms §7).
     * None of the five things THIS form reports are images: a route, a place, a
     * region description, a rider profile and a message are all text, and
     * photos have their own desk at `/media/report`. Offering it here asked a
     * reporter to consider a rule that cannot apply to what they are looking at
     * (owner, 2026-08-29: "that last one is only of interest for images").
     *
     * The case stays in the enum. `/terms` §12 publishes it, the photo flow
     * needs it, and a stored row has to keep resolving.
     */
    public function isImageOnly(): bool
    {
        return match ($this) {
            self::Generated, self::IntimateOrChild, self::PrivateProperty => true,
            default => false,
        };
    }

    /**
     * What the form offers for one kind of target, and the only values it
     * accepts for it.
     *
     * A photo gets every ground; everything else gets the ones that are not
     * about a picture. One list, one predicate, so the rule lives in the enum
     * and cannot be changed by editing a template.
     *
     * @return list<self>
     */
    public static function forTarget(ReportTarget $target): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (self $g): bool => !$g->isImageOnly() || $target->canAutoWithhold(),
        ));
    }
}
