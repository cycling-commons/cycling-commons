<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Catalog\Command;

use App\Catalog\Import\AttributeVocabulary;
use App\Catalog\ItemType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the ~23 hand-authored "hero" pins that used to live baked into
 * `web/assets/map/map.js`'s CATALOG array (famous climbs, a showcase gîte, …)
 * as real `source = manual` item rows (spec §W3, plan C3-T9).
 *
 * Until now these pins had no DB row: no id, so map.js could never show an
 * Edit button or moderate them. This command transcribes their data —
 * geometry + the subset of their demo prose that maps onto a real
 * {@see \App\Catalog\CatalogFormRegistry} field for the letter — into `item`
 * rows, so they become editable/moderatable like every other row. The
 * hardcoded CATALOG entries in map.js were removed in C3-T10 (except the
 * single letter-F hazard pin, deliberately skipped here — see the F comment
 * in {@see self::pins()}).
 *
 * Every row lands as `state = unverified` (never `verified`) — a seeded pin
 * is treated exactly like a fresh rider contribution; "verified" is only
 * ever earned through the real voting funnel.
 *
 * Idempotent: upserts by the same (source, source_ref, letter) key the
 * importer uses, with source_ref = `manual:<stable-slug>` — safe to re-run.
 *
 * Deliberately NOT persisted (documented in the C3-T9 report, not stored as
 * attributes): the demo's derived/display-only values (the literal "Length"
 * record row, the `route`/`grad`/`steep` elevation-profile arrays, the
 * pre-baked `record`/`attribution` blobs, free-text "links" and the
 * form-only "anything to correct?" intake field) and any additional entries
 * in a `photos` (plural) array beyond the first — {@see AttributeVocabulary}
 * only has a singular `photo` slot.
 *
 * @api Console entry point (dev/ops seeding tool, run once per environment).
 */
#[AsCommand(name: 'app:catalog:seed-manual', description: 'Seed the hand-authored demo pins (map.js CATALOG) as real manual item rows')]
final class SeedManualCatalogCommand extends Command
{
    /** ISO 3166-2 code for Liège — every seeded pin sits in the Amblève/Hautes-Fagnes/Spa-Stavelot area. */
    private const string SUBDIVISION_CODE = 'BE-WLG';

    /**
     * The transcribed pins, grouped in map.js CATALOG order. Each entry:
     * letter, name (-> item.name column), lat/lng (Point geometry), ref
     * (-> source_ref suffix, stable across runs) and attributes (registry-
     * validated per {@see AttributeVocabulary}).
     *
     * @return list<array{letter: string, name: string, lat: float, lng: float, ref: string, attributes: array<string, mixed>}>
     */
    private static function pins(): array
    {
        return [
            // B · Climbs
            [
                'letter' => 'B', 'name' => 'Côte de la Redoute', 'lat' => 50.49222, 'lng' => 5.69924,
                'ref' => 'manual:cote-de-la-redoute',
                'attributes' => [
                    'headline' => '2.0 km · 8.4% avg', 'cur' => true,
                    'surface' => 'Asphalt', 'sq' => 'Smooth', 'tr' => 'Quiet',
                    'avgGradient' => '8.4%', 'maxGradient' => '~20% (mid-climb ramp)', 'effort' => 'Tough',
                    'famousFor' => 'Liège–Bastogne–Liège — the decisive climb',
                    'approach' => 'From Sougné-Remouchamps (Aywaille)',
                    'waterOnClimb' => 'No',
                    'photo' => ['sm' => '/media/redoute-sm.jpg', 'lg' => '/media/redoute.jpg', 'credit' => 'DimiTalen', 'creditUrl' => 'https://commons.wikimedia.org/wiki/User:DimiTalen', 'license' => 'CC0', 'source' => 'https://commons.wikimedia.org/wiki/File:Phil_Phil_Phil_on_C%C3%B4te_de_la_Redoute,_Aywaille,_2011.jpg'],
                ],
            ],
            [
                'letter' => 'B', 'name' => 'Mur de Huy', 'lat' => 50.51426, 'lng' => 5.24874,
                'ref' => 'manual:mur-de-huy',
                'attributes' => [
                    'headline' => '1.3 km · 9.3% avg', 'cur' => true,
                    'surface' => 'Asphalt', 'sq' => 'Good', 'tr' => 'Busy',
                    'avgGradient' => '9.3%', 'maxGradient' => '~26% (Chapelle hairpin)', 'effort' => 'Very steep',
                    'famousFor' => 'La Flèche Wallonne summit finish',
                ],
            ],
            [
                'letter' => 'B', 'name' => 'Côte de Stockeu', 'lat' => 50.39148, 'lng' => 5.93247,
                'ref' => 'manual:cote-de-stockeu',
                'attributes' => [
                    'headline' => '~1.0 km · 9%+ avg', 'cur' => true,
                    'surface' => 'Worn asphalt', 'sq' => 'Worn', 'tr' => 'Quiet',
                    'avgGradient' => '9%+', 'maxGradient' => '~20%', 'effort' => 'Tough',
                    'famousFor' => 'Liège–Bastogne–Liège — Eddy Merckx stele',
                    'approach' => 'From Stavelot',
                ],
            ],
            [
                'letter' => 'B', 'name' => 'Côte de la Roche-aux-Faucons', 'lat' => 50.55573, 'lng' => 5.54831,
                'ref' => 'manual:cote-de-la-roche-aux-faucons',
                'attributes' => [
                    'headline' => '1.5 km · 9% avg', 'cur' => false,
                    'surface' => 'Asphalt', 'sq' => 'Rough', 'tr' => 'Moderate',
                    'avgGradient' => '9%', 'maxGradient' => '~11%', 'effort' => 'Challenging',
                    'famousFor' => 'Late selective climb in Liège–Bastogne–Liège',
                ],
            ],
            [
                'letter' => 'B', 'name' => 'Hockai · via RAVeL L44a', 'lat' => 50.37607, 'lng' => 5.87635,
                'ref' => 'manual:hockai-via-ravel-l44a',
                'attributes' => [
                    'headline' => "17 km · Wallonia's longest climb", 'cur' => true,
                    'surface' => 'Asphalt', 'sq' => 'Smooth', 'tr' => 'Traffic-free',
                    'avgGradient' => '~1.7%', 'effort' => 'Steady',
                    'famousFor' => "Wallonia's longest climb — RAVeL greenway drag",
                    'approach' => 'Coo / Trois-Ponts up the RAVeL greenway to the Hockai plateau',
                ],
            ],
            // C · Water & food
            [
                'letter' => 'C', 'name' => 'Public fountain · Stavelot', 'lat' => 50.3957, 'lng' => 5.9300,
                'ref' => 'manual:public-fountain-stavelot',
                'attributes' => [
                    'type' => 'Public fountain', 'potable' => 'Yes (public supply)', 'seasonal' => 'Year-round',
                    'note' => 'Public tap fountain in Stavelot town centre — verified potable on the SWDE network.',
                ],
            ],
            [
                'letter' => 'C', 'name' => 'Pouhon La Sauvenière · Spa', 'lat' => 50.4851, 'lng' => 5.8983,
                'ref' => 'manual:pouhon-la-sauveniere-spa',
                'attributes' => [
                    'type' => 'Public fountain', 'potable' => 'Yes (public supply)', 'seasonal' => 'Year-round',
                    'note' => 'Natural iron-rich mineral spring (pouhon) managed by Ville de Spa — not SWDE tap water; drew European nobility from the 1600s.',
                    'photo' => self::wc('Spa-Source de la Sauvenière (1).jpg', 'Romaine', 'Romaine', 'CC0'),
                ],
            ],
            [
                'letter' => 'C', 'name' => 'Source Barisart · Spa', 'lat' => 50.4745, 'lng' => 5.8627,
                'ref' => 'manual:source-barisart-spa',
                'attributes' => [
                    'type' => 'Public fountain', 'potable' => 'Yes (public supply)', 'seasonal' => 'Year-round',
                    'note' => 'Natural mineral spring in the woods south of Spa — not SWDE tap water; part of the historic spring-walk circuit.',
                    'photo' => self::wc('Spa-Source de Barisart (3).jpg', 'Romaine', 'Romaine', 'CC0'),
                ],
            ],
            [
                'letter' => 'C', 'name' => 'Fontaine Nicolay · Stavelot', 'lat' => 50.39249, 'lng' => 5.92637,
                'ref' => 'manual:fontaine-nicolay-stavelot',
                'attributes' => [
                    'type' => 'Public fountain', 'potable' => 'Unsigned — use judgement',
                    'note' => 'Rue Neuve, Stavelot old town (on the pavé) — OSM maps the fountain but drinking_water is unset; not yet verified with SWDE.',
                ],
            ],
            [
                'letter' => 'C', 'name' => 'Fountain · Stavelot centre', 'lat' => 50.39484, 'lng' => 5.92994,
                'ref' => 'manual:fountain-stavelot-centre',
                'attributes' => [
                    'type' => 'Public fountain', 'potable' => 'Unsigned — use judgement',
                    'note' => 'Near the abbey, Stavelot — no drinking_water tag in OSM; candidate pending an SWDE potability check.',
                ],
            ],
            // D · Bike services
            [
                'letter' => 'D', 'name' => 'Repair station · Malmedy', 'lat' => 50.4260, 'lng' => 6.0270,
                'ref' => 'manual:repair-station-malmedy',
                'attributes' => [
                    't' => 'Public repair station', 'pumpValve' => 'Presta + Schrader', 'tools' => 'Tethered multi-tool set',
                    'town' => 'Malmedy',
                    'photo' => self::wc('Fahrradreparaturstation Neustadt Hambach.jpg', 'Emilius123', 'Emilius123', 'CC BY 4.0'),
                ],
            ],
            [
                'letter' => 'D', 'name' => 'North Bike · Stavelot', 'lat' => 50.3965, 'lng' => 5.9361,
                'ref' => 'manual:north-bike-stavelot',
                'attributes' => ['t' => 'Bike shop', 'town' => 'Stavelot town'],
            ],
            [
                'letter' => 'D', 'name' => 'Ardennes Bike · Spa', 'lat' => 50.4896, 'lng' => 5.8424,
                'ref' => 'manual:ardennes-bike-spa',
                'attributes' => ['t' => 'Bike shop', 'town' => 'Spa'],
            ],
            [
                'letter' => 'D', 'name' => 'E-bike charging · Botrange', 'lat' => 50.5019, 'lng' => 6.0930,
                'ref' => 'manual:e-bike-charging-botrange',
                'attributes' => ['t' => 'E-bike charging station', 'ebikeCharging' => 'Yes', 'town' => 'Signal de Botrange plateau'],
            ],
            // E · Where to sleep
            [
                'letter' => 'E', 'name' => 'Cyclist-friendly gîte · Amblève valley', 'lat' => 50.4500, 'lng' => 5.6200,
                'ref' => 'manual:cyclist-friendly-gite-ambleve-valley',
                'attributes' => [
                    't' => 'Gîte / guesthouse', 'bikeStorage' => 'Yes — locked room',
                    'town' => 'Amblève valley, near Aywaille', 'c' => true,
                    'photo' => self::wc('Gîte rural de Puyolle.JPG', 'Darreenvt', 'Darreenvt', 'CC BY-SA 4.0'),
                ],
            ],
            // F · Hazards & conditions — deliberately SKIPPED (C3-T10, spec §W3
            // decision D3). Hazards have no serving path in CatalogProvider or
            // map.js, so a seeded manual F row could never render — it would
            // just be a permanent orphan. Letter F's single demo pin ("Exposed
            // crosswind · Hautes Fagnes") stays hardcoded in map.js's CATALOG
            // until hazards get a real serving path; don't re-add an F pin
            // here without wiring that up first.
            // G · Getting there
            [
                'letter' => 'G', 'name' => 'Aywaille station', 'lat' => 50.4730, 'lng' => 5.6770,
                'ref' => 'manual:aywaille-station',
                'attributes' => [
                    't' => 'Railway station', 'bikesOnBoard' => 'Allowed with supplement',
                    'note' => 'Line L42 · Liège – Luxembourg. Gateway to the Amblève climbs.',
                    'photo' => self::wc('Gare Aywaille.jpg', 'Les Meloures', 'Les Meloures', 'CC BY-SA 3.0 lu'),
                ],
            ],
            // H · Shelter & emergency
            [
                'letter' => 'H', 'name' => 'Shelter · Baraque Michel', 'lat' => 50.5020, 'lng' => 6.0500,
                'ref' => 'manual:shelter-baraque-michel',
                'attributes' => [
                    't' => 'Refuge / chapel shelter', 'shelterType' => 'Refuge / chapel', 'alwaysAccessible' => 'Yes — open structure',
                    'note' => 'Wind/rain refuge on exposed moorland at Baraque Michel, Hautes Fagnes.',
                    'photo' => self::wc('0 Xhoffraix - Baraque Michel - Chapelle Fischbach (1).JPG', 'Jean-Pol GRANDMONT', 'Jean-Pol GRANDMONT', 'CC BY 3.0'),
                ],
            ],
            [
                'letter' => 'H', 'name' => 'Abri Jean Poumay', 'lat' => 50.5074, 'lng' => 5.8521,
                'ref' => 'manual:abri-jean-poumay',
                'attributes' => ['t' => 'Shelter / abri', 'note' => 'Forest shelter above Spa — wind & rain refuge off the trail.'],
            ],
            [
                'letter' => 'H', 'name' => 'Belvédère de la Hoëgne', 'lat' => 50.4969, 'lng' => 5.9857,
                'ref' => 'manual:belvedere-de-la-hoegne',
                'attributes' => ['t' => 'Belvedere shelter', 'note' => 'Covered rest stop & wind/rain refuge above the Hoëgne valley, Hautes Fagnes — one of the prettiest streams in the Fagnes.'],
            ],
            [
                'letter' => 'H', 'name' => 'Picnic shelter · Pont de Baileu', 'lat' => 50.4996, 'lng' => 6.0551,
                'ref' => 'manual:picnic-shelter-pont-de-baileu',
                'attributes' => ['t' => 'Picnic shelter', 'shelterType' => 'Picnic hut', 'note' => 'Hautes Fagnes, near Mont Rigi — wait out a shower on the plateau.'],
            ],
            // I · Scenic views
            [
                'letter' => 'I', 'name' => 'Signal de Botrange', 'lat' => 50.5010, 'lng' => 6.0940,
                'ref' => 'manual:signal-de-botrange',
                'attributes' => [
                    'type' => 'Viewpoint / high point', 'bikeAccess' => 'Roadside',
                    'whatYouSee' => "Hautes Fagnes moorland — Belgium's largest nature reserve",
                    'note' => "Belgium's highest point (694 m) with the Baltia stone tower (1934) and a 1923 stone step up to exactly 700 m. Hautes Fagnes nature reserve, Waimes — the coldest, wettest spot in Belgium. For cyclists: a long, gentle plateau drag, but bleak, exposed, and often cold, windy or foggy even in summer.",
                    'c' => true,
                ],
            ],
            [
                'letter' => 'I', 'name' => 'Cascade de Coo', 'lat' => 50.39359, 'lng' => 5.87664,
                'ref' => 'manual:cascade-de-coo',
                'attributes' => [
                    'type' => 'Viewpoint / high point', 'bikeAccess' => 'Roadside',
                    'whatYouSee' => 'A ~15 m waterfall on the Amblève, enlarged by the monks of Stavelot in the 17th century',
                    'note' => 'A natural photo stop on the Amblève-valley run below the Côte de Stockeu.',
                    'c' => true,
                    'photo' => self::wc('Coo waterfall.JPG', 'Pierotreruote', 'Pierotreruote', 'CC BY-SA 3.0'),
                ],
            ],
            // J · History & culture
            [
                'letter' => 'J', 'name' => 'Stavelot Abbey', 'lat' => 50.3950, 'lng' => 5.9290,
                'ref' => 'manual:stavelot-abbey',
                'attributes' => [
                    'type' => 'Heritage site', 'note' => 'Benedictine abbey founded 651; town museums today.',
                    'cyclingStory' => 'At the foot of the Côte de Stockeu (Liège–Bastogne–Liège).',
                    'c' => true,
                ],
            ],
        ];
    }

    public function __construct(
        private readonly Connection $db,
        private readonly AttributeVocabulary $vocabulary,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->db->beginTransaction();
            $subdivisionId = $this->resolveSubdivisionId();
            $counts = [];
            foreach (self::pins() as $pin) {
                $type = ItemType::fromParam($pin['letter']);
                $this->vocabulary->assertValid($type, $pin['attributes']);

                $this->db->executeStatement(
                    'INSERT INTO item (letter, name, geom, country_code, subdivision_id, state, source, source_ref, attributes, created_at, updated_at, imported_at)
                     VALUES (:letter, :name, ST_SetSRID(ST_GeomFromGeoJSON(:geom), 4326), :cc, :sub, :state, :source, :ref, :attrs, NOW(), NOW(), NOW())
                     ON CONFLICT (source, source_ref, letter) DO UPDATE SET
                       name = EXCLUDED.name, geom = EXCLUDED.geom,
                       country_code = EXCLUDED.country_code, subdivision_id = EXCLUDED.subdivision_id,
                       attributes = EXCLUDED.attributes,
                       updated_at = CASE WHEN (item.name, ST_AsEWKB(item.geom), item.country_code, item.subdivision_id, item.attributes)
                                         IS DISTINCT FROM (EXCLUDED.name, ST_AsEWKB(EXCLUDED.geom), EXCLUDED.country_code, EXCLUDED.subdivision_id, EXCLUDED.attributes)
                                    THEN NOW() ELSE item.updated_at END,
                       imported_at = NOW()',
                    [
                        'letter' => $pin['letter'],
                        'name' => $pin['name'],
                        'geom' => json_encode(['type' => 'Point', 'coordinates' => [$pin['lng'], $pin['lat']]], \JSON_THROW_ON_ERROR),
                        'cc' => 'BE',
                        'sub' => $subdivisionId,
                        'state' => 'unverified',
                        'source' => 'manual',
                        'ref' => $pin['ref'],
                        'attrs' => json_encode($pin['attributes'], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
                    ],
                );
                $counts[$pin['letter']] = ($counts[$pin['letter']] ?? 0) + 1;
            }
            $this->recomputeMembership();
            $this->db->commit();
        } catch (\InvalidArgumentException|\JsonException|DBALException $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        ksort($counts);
        foreach ($counts as $letter => $count) {
            $io->writeln(sprintf('  %s: %d pin(s)', $letter, $count));
        }
        $io->success(sprintf('Seeded %d manual demo pin(s) across %d letter(s).', array_sum($counts), \count($counts)));

        return Command::SUCCESS;
    }

    /** @return int|null world_subdivision.id for Liège, or null when world data isn't seeded yet */
    private function resolveSubdivisionId(): ?int
    {
        $id = $this->db->fetchOne('SELECT id FROM world_subdivision WHERE code = :code', ['code' => self::SUBDIVISION_CODE]);

        return false !== $id ? (int) $id : null;
    }

    /** Only touches rows this command owns — never widens to the full item/recommended_route tables. */
    private function recomputeMembership(): void
    {
        $this->db->executeStatement(
            "UPDATE item SET region_id = r.id FROM region r WHERE item.source = 'manual' AND ST_Contains(r.geom, ST_PointOnSurface(item.geom))",
        );
    }

    /**
     * Wikimedia Commons photo helper — PHP port of map.js's `wc()`. Mirrors
     * JS `encodeURIComponent`'s unreserved set (adds back ! ' ( ) * that
     * `rawurlencode` would otherwise percent-escape) so the URL matches
     * exactly what the client would have produced for the same filename.
     *
     * @return array{sm: string, lg: string, credit: string, creditUrl: string, license: string, source: string}
     */
    private static function wc(string $file, string $credit, ?string $user, string $license): array
    {
        $enc = str_replace(['%21', '%27', '%28', '%29', '%2A'], ['!', "'", '(', ')', '*'], rawurlencode($file));
        $page = str_replace(' ', '_', $file);

        return [
            'sm' => "https://commons.wikimedia.org/wiki/Special:FilePath/{$enc}?width=520",
            'lg' => "https://commons.wikimedia.org/wiki/Special:FilePath/{$enc}?width=1400",
            'credit' => $credit,
            'creditUrl' => null !== $user ? 'https://commons.wikimedia.org/wiki/User:'.str_replace(' ', '_', $user) : '',
            'license' => $license,
            'source' => "https://commons.wikimedia.org/wiki/File:{$page}",
        ];
    }
}
