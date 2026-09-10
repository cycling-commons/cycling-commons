<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
     * A drawn 24-box path for every type, one colour, filled with
     * currentColor (owner 2026-09-09: "no coloured icons"; the emoji glyphs
     * painted themselves in whatever colours the platform's font chose). The
     * map's own paths (they used to live in icons.js). The glyph stays as the
     * text fallback. Quality rides are a route winding across the land, a
     * ribbon rather than a star (owner: "an icon of a route across a
     * landscape, a slinger line").
     */
    public function svgPath(): string
    {
        return match ($this) {
            self::RoadSurface => 'M7 6h15l-5 12H2z',
            self::WaterFood => 'M12 2C12 2 5 10 5 15a7 7 0 0 0 14 0c0-5-7-13-7-13Z',
            self::BikeServices => 'M21.7 6.3a5.5 5.5 0 0 1-7.4 6.9L7 20.5a2.1 2.1 0 0 1-3-3l7.3-7.3a5.5 5.5 0 0 1 6.9-7.4l-3.3 3.3 1.1 3.1 3.1 1.1 3.3-3.3Z',
            self::WhereToSleep => 'M12 3L23 20H1L12 3Zm0 5.5L6.2 18h4.6v-4h2.4v4h4.6L12 8.5Z',
            self::Hazards => 'M12 2 23 21H1L12 2Zm-1 7v6h2V9h-2Zm0 7.5v2h2v-2h-2Z',
            self::GettingThere => 'M6 3h12a2 2 0 0 1 2 2v10a3 3 0 0 1-3 3l1.5 3h-2l-1.5-3H9l-1.5 3h-2L7 18a3 3 0 0 1-3-3V5a2 2 0 0 1 2-2Zm0 3v5h12V6H6Zm1.5 7.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Zm9 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z',
            self::Shelter => 'M12 3l10 8h-3v9H5v-9H2l10-8Zm-2 9v7h4v-7h-4Z',
            self::Climbs => 'M2 20 L9.5 6 L13 12 L16 8 L22 20 Z',
            self::HistoryCulture => 'M12 2 22 7v2H2V7l10-5ZM3 10h3v8H3zm5.5 0h3v8h-3zm5.5 0h3v8h-3zm5.5 0h3v8h-3zM2 19h20v3H2z',
            self::QualityRides => 'M2 16C5 8 9 8 12 13s7 5 10-3l1.8 1.1C20.3 20 15.3 20.6 11.5 14.6S6.2 9.2 3.8 17.1L2 16Z',
            self::ScenicViews => 'M9 4h6l1.5 2.5H20a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8.5a2 2 0 0 1 2-2h3.5L9 4Zm3 4.6a4.7 4.7 0 1 0 0 9.4 4.7 4.7 0 0 0 0-9.4Zm0 2a2.7 2.7 0 1 1 0 5.4 2.7 2.7 0 0 1 0-5.4Z',
            self::PublicToilets => 'M5 3h5.6v8H5zM4 12h16a1 1 0 0 1 1 1.1c-.3 3.4-2.3 6-4.9 7.1v1.3a.9.9 0 0 1-.9.9H8.8a.9.9 0 0 1-.9-.9v-1.3C5.3 19.1 3.3 16.5 3 13.1A1 1 0 0 1 4 12z',
        };
    }

    /**
     * Letter → {glyph, svg} for every type: the payload both the map and the
     * Twig partial consume.
     *
     * @return array<string, array{glyph: string, svg: string}>
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
