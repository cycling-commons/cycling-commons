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
     * confirmation. Votable types (see {@see isVotable()}) and the measured
     * road-surface segment carry none: they are empty here.
     *
     * @return list<ConfirmationStance>
     */
    public function confirmationStances(): array
    {
        return match ($this) {
            self::WaterFood => [ConfirmationStance::Potable, ConfirmationStance::NotPotable],
            self::BikeServices, self::Hazards, self::GettingThere, self::Shelter, self::PublicToilets => [ConfirmationStance::Exists],
            default => [],
        };
    }

    /** True when riders confirm (rather than vote on) this type. */
    public function isConfirmable(): bool
    {
        return [] !== $this->confirmationStances();
    }
}
