<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * The canonical A–K catalog of editable item types.
 *
 * Single source of truth for the per-type contribution forms, matching the
 * demo's `atlas/demo/edit-items.js` registry. Design source of truth:
 * docs/specs/edit-items/<LETTER>-*.md and its README.md.
 *
 * Intrinsic metadata (letter, label, icon, location mode, votability) lives
 * here; the bulkier per-type field schemas live in {@see CatalogFormRegistry}.
 *
 * L · Ride heatmap is a derived, anonymized aggregate. It is intentionally
 * NOT an editable type, so it is not represented here — which is why
 * M · Public toilets (added 2026-07-30) skips over L. Letters are stable
 * storage identifiers, never display order: surfaces that list categories
 * order them editorially (M renders next to C · Water & food).
 *
 * Since 2026-08-02 they are not display *anything* — no rider-facing surface
 * prints a letter. It survives in `?type=`, coverage tiles, `coverage_poi`
 * and the public API; the rail, drawer, search rows, hub cards and wizard
 * eyebrows show the type's icon and name (map-and-search.md §4.1).
 *
 * @api Public catalog surface consumed by the improve form, controller and
 *      templates. `@api` tells Psalm these members are entry points, not dead.
 */
enum ItemType: string
{
    case RoadSurface = 'road-surface';
    case Climbs = 'climbs';
    case WaterFood = 'water-food';
    case BikeServices = 'bike-services';
    case WhereToSleep = 'where-to-sleep';
    case Hazards = 'hazards';
    case GettingThere = 'getting-there';
    case Shelter = 'shelter';
    case ScenicViews = 'scenic-views';
    case HistoryCulture = 'history-culture';
    case QualityRides = 'quality-rides';
    case PublicToilets = 'public-toilets';

    /**
     * Resolve a URL/query value to a type. Accepts a canonical slug (the nice
     * ?type=water-food form the hub uses) or a catalog letter A–K (the
     * identifier the map layers carry, layer.letter). Falls back to the default
     * when null/empty/unknown. It mirrors the demo's fallback to the service
     * station for a bare improve page.
     */
    public static function fromParam(?string $value): self
    {
        if (null === $value || '' === $value) {
            return self::default();
        }

        if (null !== $slug = self::tryFrom($value)) {
            return $slug;
        }

        $letter = strtoupper($value);
        foreach (self::cases() as $type) {
            if ($type->letter() === $letter) {
                return $type;
            }
        }

        return self::default();
    }

    /** D · Bike services: the demo's no-param fallback item. */
    public static function default(): self
    {
        return self::BikeServices;
    }

    /**
     * The type a catalog letter belongs to, or null for a letter nothing uses.
     *
     * The inverse of {@see letter()}. Written because three call sites had each
     * open-coded the same `foreach (self::cases())` walk to answer it.
     */
    public static function fromLetter(string $letter): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->letter() === $letter) {
                return $case;
            }
        }

        return null;
    }

    /** The monotonic catalog letter A–K. */
    public function letter(): string
    {
        return match ($this) {
            self::RoadSurface => 'A',
            self::Climbs => 'B',
            self::WaterFood => 'C',
            self::BikeServices => 'D',
            self::WhereToSleep => 'E',
            self::Hazards => 'F',
            self::GettingThere => 'G',
            self::Shelter => 'H',
            self::ScenicViews => 'I',
            self::HistoryCulture => 'J',
            self::QualityRides => 'K',
            self::PublicToilets => 'M',
        };
    }

    /** Human label (translation key base; also the catalog label). */
    public function label(): string
    {
        return match ($this) {
            self::RoadSurface => 'Road surface',
            self::Climbs => 'Climbs',
            self::WaterFood => 'Water & food',
            self::BikeServices => 'Bike services',
            self::WhereToSleep => 'Where to sleep',
            self::Hazards => 'Hazards & conditions',
            self::GettingThere => 'Getting there',
            self::Shelter => 'Shelter & emergency',
            self::ScenicViews => 'Scenic views',
            self::HistoryCulture => 'History & culture',
            self::QualityRides => 'Recommended routes',
            self::PublicToilets => 'Public toilets',
        };
    }

    /**
     * Translation key for {@see label()}. Use with `|trans` so localized
     * improve pages don't render the hardcoded English.
     */
    public function labelKey(): string
    {
        return 'item_type.'.$this->value.'.label';
    }

    /** Translation key for {@see eyebrow()}. */
    public function eyebrowKey(): string
    {
        return 'item_type.'.$this->value.'.eyebrow';
    }

    /** The locator pin glyph shown in the wizard (from edit-items.js). */
    public function icon(): string
    {
        return match ($this) {
            self::RoadSurface => '▰',
            self::Climbs => '⛰',
            self::WaterFood => '💧',
            self::BikeServices => '⚙',
            self::WhereToSleep => '⛺',
            self::Hazards => '⚠',
            self::GettingThere => '🚆',
            self::Shelter => '⛑',
            self::ScenicViews => '◬',
            self::HistoryCulture => '🏛',
            self::QualityRides => '★',
            self::PublicToilets => '🚻',
        };
    }

    /** The wizard eyebrow line ("Improve this …"). */
    public function eyebrow(): string
    {
        return match ($this) {
            self::RoadSurface => 'Improve this segment',
            self::Climbs => 'Improve this climb',
            self::WaterFood => 'Improve this water point',
            self::BikeServices => 'Improve this place',
            self::WhereToSleep => 'Improve this stay',
            self::Hazards => 'Update this hazard',
            self::GettingThere => 'Improve this place',
            self::Shelter => 'Improve this place',
            self::ScenicViews => 'Improve this viewpoint',
            self::HistoryCulture => 'Improve this place',
            self::QualityRides => 'Edit this ride',
            self::PublicToilets => 'Improve this place',
        };
    }

    /** How step 1 sets the location for this type. */
    public function locationMode(): LocationMode
    {
        return match ($this) {
            self::RoadSurface => LocationMode::Segment,
            self::QualityRides => LocationMode::None,
            default => LocationMode::Point,
        };
    }

    /**
     * Votable types climb the funnel toward best-of; utility/coverage types
     * stop at verified and are never votable (README funnel table).
     */
    public function isVotable(): bool
    {
        return match ($this) {
            self::Climbs,
            self::WhereToSleep,
            self::ScenicViews,
            self::HistoryCulture,
            self::QualityRides => true,
            default => false,
        };
    }

    /** Only rides carry a GPX/FIT track upload (in addition to photos). */
    public function hasTrackUpload(): bool
    {
        return self::QualityRides === $this;
    }

    /**
     * The community-confirmation stances a rider may take on this type
     * (spec: utilities are confirmed, not voted). Drinking water carries a
     * potability judgement; other point utilities carry a plain existence
     * confirmation; a road-surface segment carries it as "as described".
     * Votable-only types (see {@see isVotable()}) are empty here.
     *
     * @return list<ConfirmationStance>
     */
    public function confirmationStances(): array
    {
        return match ($this) {
            self::WaterFood => [ConfirmationStance::Potable, ConfirmationStance::NotPotable],
            /* Every place a rider can stand next to can be confirmed.

               The first version of this excluded climbs on the grounds that "a
               mountain does not go anywhere" — and the owner pointed at a
               castle, which does not go anywhere either and was confirmable
               (2026-08-12). The rule was wrong, not the exception: **"could it
               vanish" is the wrong test.** A confirmation is a rider saying *I
               was there and this is right* — that the place exists, that it is
               where we say, that it is what we call it. A climb can be wrong
               about all three, and nobody standing at the foot of it is
               short of an opinion.

               Confirming is still not voting. Voting ranks a region's best;
               confirming vouches for the entry. A letter can want both, and
               most do. */
            self::BikeServices, self::Hazards, self::GettingThere, self::Shelter,
            self::PublicToilets, self::ScenicViews, self::HistoryCulture,
            self::WhereToSleep, self::Climbs => [ConfirmationStance::Exists],
            /* A surface segment joined 2026-08-13, by the same correction that
               brought climbs in: it was excluded as "measured", but a rider
               who rode the stretch is exactly who can vouch that it is as
               described — and the drawer's status line was literally saying
               "not confirmed yet" to a second rider with no way to answer
               (owner-reported). The tile drawer's confirm flow had promised
               "from then on the ordinary one-tap item confirmation applies";
               this makes that true. Its "no" is a stance of its own, like
               water's: the stretch is there, but not as described — and may
               carry a note for the curators. */
            self::RoadSurface => [ConfirmationStance::Exists, ConfirmationStance::NotAsDescribed],
            default => [],
        };
    }

    /** True when riders confirm (rather than vote on) this type. */
    public function isConfirmable(): bool
    {
        return [] !== $this->confirmationStances();
    }

    /**
     * True when a confirmation of this type GOES OFF - when "somebody checked
     * this" stops being worth much after a while, so the map is right to nudge.
     *
     * A narrower question than {@see isConfirmable()}, and deliberately a
     * separate list rather than a reuse of it. Every place a rider can stand
     * next to is confirmable, because a rider standing there can say the entry
     * is wrong about anything; but **a tap breaks, a shop shuts, a hazard
     * clears - a viewpoint does not stop being a view.** Ageing a viewpoint
     * would put an orange border on most of the map six months after launch,
     * and an orange that means "everything" means nothing, which is the trap
     * this whole signal has to survive (docs/TODO.md, 2026-08-12).
     *
     * So: the built world ages, the landscape does not. Climbs, scenic views
     * and history sit out - a col is where it was, and if the entry is wrong
     * about it that is a correction, not a staleness.
     *
     * `ClosureLifetime` is the same idea already built for one letter: a
     * closure retires itself, and a confirmation ages itself.
     */
    public function confirmationAges(): bool
    {
        return match ($this) {
            // A tap, a shop, a toilet, a shelter, a bed, a station: all built,
            // all maintained by somebody, all able to stop existing quietly.
            self::WaterFood, self::BikeServices, self::WhereToSleep,
            self::GettingThere, self::Shelter, self::PublicToilets => true,
            // A hazard is the strongest case of all: the whole value of the
            // report is that it is CURRENT, and a cleared hazard left standing
            // is worse than no hazard at all.
            self::Hazards => true,
            // Roads get resurfaced, and a surface report is a description of a
            // condition rather than of a place.
            self::RoadSurface => true,
            // Climbs, scenic views, history: confirmable, never stale.
            default => false,
        };
    }

    /**
     * True when 'Out of order' can be true of this type.
     *
     * A tap, a pump and a toilet have working parts and can be broken while
     * still standing exactly where the map says. A viewpoint, a shelter, a
     * monument and a station platform cannot: they are there, or shut, or
     * gone. Offering a rider an answer that cannot be true of what they are
     * looking at teaches them to distrust the rest of the row.
     *
     * The client has drawn this line since 2026-08-12 (`CC_BREAKABLE`,
     * community.js) and the wizard had not, so the form offered what the map
     * refused (owner-reported 2026-08-14). This method is the server's copy of
     * that set, used both to pick the form's menu
     * ({@see CatalogFormRegistry}) and to refuse the stance at the confirm
     * endpoint, so the narrower menu is a rule rather than a suggestion the UI
     * makes.
     */
    public function canBreak(): bool
    {
        return match ($this) {
            self::WaterFood, self::BikeServices, self::PublicToilets => true,
            default => false,
        };
    }
}
