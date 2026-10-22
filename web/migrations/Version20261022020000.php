<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The `wallonie-pivot` register row reads Tourisme Wallonie's PIVOT register
 * from the live Géoportail Wallonie ArcGIS service, the CC BY 4.0 copy
 * (data-provider-hierarchy.md §10): its six stay layers, PIVOT's `CODE` as
 * the id, the name, type, town and website, and, for a stay without a
 * website, its Maison du Tourisme's website as `officeWeb`. Never the email,
 * the phone or the street address. PIVOT changes as the tourist offices edit
 * it, so the row refreshes monthly.
 */
final class Version20261022020000 extends AbstractMigration
{
    private const string ENDPOINT = 'https://geoservices.wallonie.be/arcgis/rest/services/TOURISME/OFFRES_TOURISTIQUES/MapServer';

    /** PIVOT's `ORGANISME` (the stay's Maison du Tourisme) and that office's website, each checked 2026-10-22. */
    private const array OFFICES = [
        'Brabant Wallon' => 'https://www.destinationbw.be/',
        'Centrissime' => 'https://www.centrissime.be/',
        'Condroz-Famenne' => 'https://www.destinationcondroz.be/',
        "Cœur de l'Ardenne, au fil de l'Ourthe & de l'Aisne" => 'https://www.coeurdelardenne.be/',
        'Explore Meuse' => 'https://www.exploremeuse.be/',
        'Famenne-Ardenne' => 'https://www.famenneardenne.be/',
        'Forêt de Saint-Hubert' => 'https://www.foretdesainthubert-tourisme.be/',
        'Gaume' => 'https://www.visitgaume.be/',
        'Haute Ardenne' => 'https://www.haute-ardenne.be/',
        "Haute-Süre Forêt d'Anlier en Ardenne" => 'https://www.capsureanlier.be/',
        "Hautes Fagnes - Cantons de l'Est" => 'https://www.ostbelgien.eu/',
        'Ourthe Vesdre Amblève' => 'https://www.visitova.be/',
        "Pays d'Arlon" => 'https://www.paysdarlon.be/',
        'Pays de Bastogne' => 'https://www.paysdebastogne.be/',
        'Pays de Bouillon en Ardenne' => 'https://www.paysdebouillon.be/',
        'Pays de Charleroi' => 'https://www.cm-tourisme.be/',
        'Pays de Herve' => 'https://www.paysdeherve.be/',
        'Pays de Liège' => 'https://www.visitezliege.be/',
        'Pays de Vesdre' => 'https://www.paysdevesdre.be/',
        'Pays des Lacs' => 'https://www.cm-tourisme.be/',
        'Région de Mons' => 'https://www.visitmons.be/',
        'Sambre-Orneau' => 'https://www.terracuriosa.be/',
        'Spa Hautes Fagnes Ardennes' => 'https://www.visitspa-hautesfagnes.be/',
        'Terres-de-Meuse' => 'https://terres-de-meuse.be/',
        'Wallonie Picarde (Antenne Mouscron)' => 'https://www.visitmouscron.be/',
        'Wallonie Picarde (Antenne Tournai)' => 'https://www.visitwapi.be/',
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'wallonie-pivot reads the live PIVOT register (ArcGIS), monthly, with the tourist office for stays without a website';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $fieldMap = [
            // Hotels, gîtes, B&Bs, furnished rentals, campsites, budget stays (7, holiday villages, is empty).
            '_layers' => [1, 3, 13, 4, 5, 6],
            '_id' => 'CODE',
            'name' => 'NOM',
            'town' => 'COMMUNE',
            'web' => 'SITE_WEB',
            'type' => ['from' => 'TYPE_OFFRE', 'values' => [
                'Hôtel' => 'hotel',
                'Gîte' => 'chalet',
                'Chambre d’hôtes' => 'guest_house',
                "Chambre d'hôtes" => 'guest_house',
                'Meublé' => 'apartment',
                'Camping' => 'camp',
                'Budget Holiday' => 'hostel',
            ]],
            // Every stay's office; the ingest keeps it only beside no live website (ProviderHarvest).
            'officeWeb' => ['from' => 'ORGANISME', 'values' => self::OFFICES],
        ];

        $this->addSql(
            "UPDATE data_provider SET endpoint = :endpoint, endpoint_kind = 'arcgis', field_map = CAST(:map AS jsonb),
                    refresh_cadence = 'monthly' WHERE provider_key = 'wallonie-pivot'",
            ['endpoint' => self::ENDPOINT, 'map' => json_encode($fieldMap, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)],
        );
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE data_provider SET endpoint = NULL, endpoint_kind = NULL, field_map = '{}'::jsonb, refresh_cadence = 'yearly' WHERE provider_key = 'wallonie-pivot'");
    }
}
