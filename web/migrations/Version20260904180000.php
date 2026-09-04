<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The Dutch public drinking-water taps: the registry's first real dataset.
 *
 * A row, not a script. Everything the harvester needs is here: the service,
 * the layer, which upstream field feeds which attribute, the letter it fills
 * and how close a point must be to an OpenStreetMap node to be the same tap.
 * That is the whole point of the registry, and this is the test of it.
 *
 * **Seeded PAUSED.** `/credits` lists every enabled provider, and until a
 * harvest has actually run there is not one row on the map from RIVM: naming
 * them would be a claim about the future on a page whose job is to be true,
 * which is the same reason the Georegister row is commented out. Enabling it
 * is one deliberate act on the desk, at the moment it becomes true.
 *
 * **No `creator` yet.** The dataset's creator is drinkwaterkaart.nl, and
 * crediting them here would put them back on a page they were deliberately
 * taken off until somebody has spoken to their maintainer (owner, 2026-08-26).
 * The field goes in with that conversation, not before it.
 *
 * **The ref is coordinate-derived, and that is a decision.** RIVM feature ids
 * are positional (`rivm_drinkwaterkranen_actueel.1`) and a republish can
 * renumber every one of them, so the field map carries no `_id` and the
 * harvester falls back to rounded coordinates. A tap that MOVES is therefore a
 * delete plus an insert rather than an update, which is the honest reading:
 * a tap 200 m away is a different tap until somebody says otherwise.
 *
 * **`beschrijvi` is the NOTE, not the name.** It runs to 254 characters and
 * reads as a paragraph ("Watertappunt in de entreehal van 't Ailand
 * Lauwersoog. Deur van de hal is dag en nacht open. ..."), which is a note for
 * a rider and not a title; `item.name` holds 200. RIVM does not name its taps,
 * so these rows carry no name, which is the honest answer.
 *
 * **RIVM's own `type` is dropped for now.** Its values are availability
 * (`Regulier, 24-7 open` / `Alleen overdag bereikbaar` / `Storing`) and letter
 * B has no field that means that: `type` there is what KIND of water point it
 * is, and `seasonal` is about the season. Inventing an attribute with no form
 * field behind it would break the rule that anything filled in for a rider
 * must be editable by one. It goes in when B gains an availability field
 * (docs/specs/edit-items/B-water-food.md).
 *
 * @see docs/specs/data-provider-hierarchy.md §11
 * @see docs/specs/data-source-register.md (licence admission)
 */
final class Version20260904180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Registry row for the RIVM Dutch drinking-water taps (paused until its first harvest)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO data_provider
                (provider_key, name, full_name, homepage, licence, licence_code, rank,
                 country_code, letters, attribution, blurb, refresh_cadence,
                 endpoint, endpoint_kind, field_map, match_radius_m, system, enabled)
            VALUES
                ('rivm-drinkwater', 'RIVM',
                 'Openbare drinkwaterkranen, RIVM / Atlas Leefomgeving',
                 'https://www.atlasleefomgeving.nl/openbare-drinkwaterpunten-0',
                 'Public Domain Mark 1.0', 'pdm-1.0', 400,
                 'NL', '["B"]'::jsonb,
                 NULL,
                 'The Dutch register of public drinking-water taps, published by RIVM. Where a tap is already on OpenStreetMap the two are one pin; where it is not, the register fills a gap nobody had mapped.',
                 'twice yearly',
                 'https://data.rivm.nl/geo/alo/wfs', 'wfs',
                 '{"_layer": "alo:rivm_drinkwaterkranen_actueel", "note": "beschrijvi", "town": "plaats"}'::jsonb,
                 50, FALSE, FALSE)
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Rows first: the FK is ON DELETE SET NULL, so dropping the provider
        // with rows behind it would leave authority rows citing nobody.
        $this->addSql("DELETE FROM item WHERE provider_id = (SELECT id FROM data_provider WHERE provider_key = 'rivm-drinkwater')");
        $this->addSql("DELETE FROM data_provider WHERE provider_key = 'rivm-drinkwater'");
    }
}
