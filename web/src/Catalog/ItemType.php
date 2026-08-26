<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog;

/**
 * Editable catalog types. Practical types take letters A-M, experiential types N-Z, so each half can grow without colliding. The heat layer is derived and carries no letter. Letters are storage ids, not display order.
 *
 * @see docs/specs/edit-items/README.md
 *
 * @api
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

    /** Slug (`?type=water-food`) or letter (A-G, N-R); unknown falls back to {@see default()}. */
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

    /** Inverse of {@see letter()}; null if nothing uses that letter. */
    public static function fromLetter(string $letter): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->letter() === $letter) {
                return $case;
            }
        }

        return null;
    }

    /** Catalog letter: practical A-G, experiential N-R (A-M and N-Z are the two halves). */
    public function letter(): string
    {
        return match ($this) {
            self::RoadSurface => 'A',
            self::Climbs => 'N',
            self::WaterFood => 'B',
            self::BikeServices => 'D',
            self::WhereToSleep => 'O',
            self::Hazards => 'E',
            self::GettingThere => 'F',
            self::Shelter => 'G',
            self::ScenicViews => 'P',
            self::HistoryCulture => 'Q',
            self::QualityRides => 'R',
            self::PublicToilets => 'C',
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

    /**
     * THE category glyph, one per type, for every surface (owner 2026-08-25:
     * "use the same as in the map, and make sure these are the only ones in
     * the system"). The map reads this set as `window.CC_TYPE_ICONS`
     * (MapController), server pages through `cc_type_icons()`
     * (partials/_type_icon.html.twig). Nothing else may define a category icon.
     */
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
            self::ScenicViews => '📷',
            self::HistoryCulture => '🏛',
            self::QualityRides => '★',
            self::PublicToilets => '🚻',
        };
    }

    /**
     * A drawn 24-box path for the types whose glyph is not good enough as a
     * pin or a row icon; the map's own paths (they used to live in icons.js).
     * Null = the glyph is the icon.
     */
    public function svgPath(): ?string
    {
        return match ($this) {
            self::Climbs => 'M2 20 L9.5 6 L13 12 L16 8 L22 20 Z',
            self::ScenicViews => 'M9 4h6l1.5 2.5H20a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.5a2 2 0 0 1 2-2h3.5L9 4Zm3 4.6a4.7 4.7 0 1 0 0 9.4 4.7 4.7 0 0 0 0-9.4Zm0 2a2.7 2.7 0 1 1 0 5.4 2.7 2.7 0 0 1 0-5.4Z',
            self::PublicToilets => 'M5 3h5.6v8H5zM4 12h16a1 1 0 0 1 1 1.1c-.3 3.4-2.3 6-4.9 7.1v1.3a.9.9 0 0 1-.9.9H8.8a.9.9 0 0 1-.9-.9v-1.3C5.3 19.1 3.3 16.5 3 13.1A1 1 0 0 1 4 12z',
            default => null,
        };
    }

    /**
     * Letter → {glyph, svg} for every type: the payload both the map and the
     * Twig partial consume.
     *
     * @return array<string, array{glyph: string, svg: ?string}>
     */
    public static function iconSet(): array
    {
        $set = [];
        foreach (self::cases() as $t) {
            $set[$t->letter()] = ['glyph' => $t->icon(), 'svg' => $t->svgPath()];
        }

        return $set;
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

    /** docs/specs/edit-items/README.md (Item lifecycle and votability) */
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
     * Stances a rider may take. Empty for types that only vote.
     *
     * @see docs/specs/moderation-and-contribution.md §10.1
     *
     * @return list<ConfirmationStance>
     */
    public function confirmationStances(): array
    {
        return match ($this) {
            self::WaterFood => [ConfirmationStance::Potable, ConfirmationStance::NotPotable],
            self::BikeServices, self::Hazards, self::GettingThere, self::Shelter,
            self::PublicToilets, self::ScenicViews, self::HistoryCulture,
            self::WhereToSleep, self::Climbs => [ConfirmationStance::Exists],
            // docs/specs/edit-items/A-road-surface.md (Confirmation: "as described")
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
     * Confirmations that go stale. Narrower than {@see isConfirmable()}: the built world ages; a viewpoint does not stop being a view.
     *
     * @see docs/specs/moderation-and-contribution.md §10.1a
     */
    public function confirmationAges(): bool
    {
        return match ($this) {
            self::WaterFood, self::BikeServices, self::WhereToSleep,
            self::GettingThere, self::Shelter, self::PublicToilets => true,
            self::Hazards => true,
            self::RoadSurface => true,
            default => false,
        };
    }

    /**
     * Whether 'Out of order' can be true. Keep in step with `CC_BREAKABLE`.
     *
     * @see docs/specs/catalog-data-model.md §7
     */
    public function canBreak(): bool
    {
        return match ($this) {
            self::WaterFood, self::BikeServices, self::PublicToilets => true,
            default => false,
        };
    }
}
