<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Map presets: a Best of season or bike chip that is on is used, one that is off is not, as in every chip row
 * (docs/specs/map-presets.md section 1). A preset stored with no season or no bike meant every one; it now holds
 * every one, so it shows the same Best of routes. Lists in PresetInput's order (SEASONS, BikeType::cases()).
 */
final class Version20261028010000 extends AbstractMigration
{
    private const string SEASONS = '["spring", "summer", "autumn", "winter"]';
    private const string BIKES = '["Road", "Gravel", "MTB", "E-bike", "Handbike", "Recumbent", "Trike", "Tandem"]';

    #[\Override]
    public function getDescription(): string
    {
        return 'map_preset: an empty Best of season or bike list becomes every one';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE map_preset SET settings = jsonb_set(settings, '{seasons}', '".self::SEASONS."'::jsonb) WHERE COALESCE(settings->'seasons', '[]'::jsonb) = '[]'::jsonb");
        $this->addSql("UPDATE map_preset SET settings = jsonb_set(settings, '{bikes}', '".self::BIKES."'::jsonb) WHERE COALESCE(settings->'bikes', '[]'::jsonb) = '[]'::jsonb");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE map_preset SET settings = jsonb_set(settings, '{seasons}', '[]'::jsonb) WHERE settings->'seasons' = '".self::SEASONS."'::jsonb");
        $this->addSql("UPDATE map_preset SET settings = jsonb_set(settings, '{bikes}', '[]'::jsonb) WHERE settings->'bikes' = '".self::BIKES."'::jsonb");
    }
}
