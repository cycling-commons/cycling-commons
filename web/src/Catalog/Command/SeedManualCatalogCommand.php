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
 * Collision-safe (C4-T11): before inserting/upserting a pin, skips it if a
 * NON-manual item already exists with the same (name, letter) — i.e. never
 * seeds a manual duplicate of a place the OSM/pivot/… harvest already
 * imported. Skipped pins are reported on stdout.
 *
 * C5 data-loss fix: the original C3-T9 cut also DROPPED `route`/`grad`/
 * `steep` (the climb line + gradient profile + steepest-ramp marker) and
 * collapsed every demo pin's `photos` (plural) gallery down to a single
 * `photo` — losing real map/drawer content, not just derived prose. These
 * are now restored verbatim from the pre-migration map.js CATALOG (git
 * `8bae43d^`) as attributes: `route`/`grad`/`steep` are already valid
 * letter-B vocab keys (imported climbs carry them — see
 * {@see \App\Catalog\CatalogProvider::climbs()}), and `photos` (plural) is
 * now a {@see AttributeVocabulary} COMMON key so any letter can carry a
 * gallery (map.js's `photoList(f)` already prefers `f.photos` over
 * `f.photo`).
 *
 * Still deliberately NOT persisted (documented in the C3-T9 report): the
 * demo's derived/display-only values — the literal "Length" record row, the
 * pre-baked `record`/`attribution` blobs (already decomposed into discrete
 * registry fields: `avgGradient`, `maxGradient`, `famousFor`, `approach`,
 * `waterOnClimb`, `surface`), free-text "links", and the form-only "anything
 * to correct?" intake field.
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
                    'route' => [[50.48321, 5.70391], [50.48327, 5.70383], [50.4837, 5.70348], [50.48396, 5.70319], [50.48421, 5.70315], [50.48453, 5.70322], [50.48522, 5.70341], [50.48535, 5.70344], [50.48554, 5.7034], [50.48585, 5.70317], [50.48603, 5.70308], [50.48613, 5.70317], [50.48626, 5.70361], [50.48636, 5.70418], [50.48655, 5.70479], [50.48708, 5.70577], [50.48807, 5.70728], [50.48833, 5.70767], [50.48853, 5.70774], [50.48887, 5.70757], [50.48912, 5.70742], [50.48971, 5.70703], [50.4903, 5.70637], [50.49063, 5.70593], [50.49077, 5.70583], [50.49094, 5.70564], [50.49101, 5.70534], [50.49097, 5.70485], [50.49091, 5.70383], [50.49097, 5.70337], [50.49118, 5.7022], [50.4913, 5.7019], [50.49149, 5.70163], [50.49181, 5.70131], [50.49206, 5.70094], [50.49219, 5.70057], [50.49226, 5.69965], [50.49217, 5.6991], [50.49203, 5.69881], [50.49167, 5.6984], [50.49126, 5.6978], [50.49098, 5.69734], [50.49045, 5.69635], [50.48999, 5.69579], [50.48989, 5.69571]],
                    'grad' => [4, 6, 8, 11, 14, 18, 20, 16, 12, 9, 7, 8],
                    'steep' => ['at' => [50.49077, 5.70583], 'pct' => '~20%'],
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
                    'photos' => [
                        self::wc('2019 Mur de Huy 3.jpg', 'Hoebele', 'Hoebele', 'CC BY-SA 4.0'),
                        ['sm' => '/media/mur-de-huy-sm.jpg', 'lg' => '/media/mur-de-huy.jpg', 'credit' => 'Rz98', 'creditUrl' => 'https://commons.wikimedia.org/wiki/User:Rz98', 'license' => 'CC BY-SA 4.0', 'source' => 'https://commons.wikimedia.org/wiki/File:Mur_de_Huy_001.jpg'],
                    ],
                    'route' => [[50.51656, 5.24049], [50.51655, 5.24056], [50.51653, 5.24097], [50.51654, 5.2414], [50.51665, 5.24191], [50.51686, 5.2423], [50.51707, 5.24266], [50.51729, 5.243], [50.51749, 5.24333], [50.51793, 5.24398], [50.51836, 5.2446], [50.51865, 5.24501], [50.51877, 5.24521], [50.51886, 5.24586], [50.51887, 5.24652], [50.51879, 5.24692], [50.51867, 5.2471], [50.51765, 5.24788], [50.51751, 5.24792], [50.51692, 5.24757], [50.51687, 5.24722], [50.51684, 5.24707], [50.51623, 5.24608], [50.51611, 5.24609], [50.516, 5.2465], [50.51547, 5.24665], [50.51519, 5.24678], [50.51461, 5.24726], [50.51442, 5.24753], [50.51433, 5.24777], [50.51426, 5.24831], [50.51425, 5.24875], [50.5142, 5.24926], [50.51411, 5.24997], [50.51403, 5.25064]],
                    'grad' => [6, 9, 13, 17, 21, 26, 23, 16, 11, 9, 8],
                    'steep' => ['at' => [50.51765, 5.24788], 'pct' => '26%'],
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
                    'photos' => [
                        ['sm' => '/media/stockeu-sm.jpg', 'lg' => '/media/stockeu.jpg', 'credit' => 'Hoebele', 'creditUrl' => 'https://commons.wikimedia.org/wiki/User:Hoebele', 'license' => 'CC BY-SA 4.0', 'source' => 'https://commons.wikimedia.org/wiki/File:Stavelot_Stockeu_Eddy_Merckx_monument.jpg'],
                        self::wc('Monument Eddy Merckx Stockeu.jpg', 'Les Meloures', 'Les Meloures', 'CC BY-SA 4.0'),
                    ],
                    'route' => [[50.39147, 5.93248], [50.39142, 5.93255], [50.39137, 5.93262], [50.39126, 5.93276], [50.39102, 5.93308], [50.39083, 5.9333], [50.39073, 5.9334], [50.39062, 5.93349], [50.39038, 5.93363], [50.38988, 5.93391], [50.38955, 5.93408], [50.38949, 5.93408], [50.38918, 5.93395], [50.389, 5.93386], [50.38886, 5.93382], [50.38868, 5.93378], [50.38839, 5.93377], [50.38773, 5.93402], [50.38737, 5.93415], [50.38716, 5.9342], [50.38698, 5.93426], [50.3869, 5.93432], [50.38682, 5.93439], [50.3866, 5.93464], [50.38638, 5.93488], [50.38626, 5.93499], [50.38601, 5.93513], [50.38553, 5.93545], [50.38445, 5.93607], [50.38419, 5.9362], [50.38409, 5.93626], [50.38404, 5.9363], [50.38395, 5.93638], [50.38386, 5.93649], [50.38369, 5.93674], [50.38345, 5.93706], [50.3833, 5.93726], [50.38327, 5.93732]],
                    'grad' => [7, 10, 14, 18, 20, 17, 13, 10, 8, 9],
                    'steep' => ['at' => [50.38773, 5.93402], 'pct' => '~20%'],
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
                    'photos' => [
                        self::wc('Philippe Gilbert LBL 2009 Roche aux faucons.jpg', 'Les Meloures', 'Les Meloures', 'CC BY-SA 2.5'),
                        self::wc('Cote de la Roche-aux-faucons01.jpg', 'Bel Adone', null, 'Public domain'),
                    ],
                    'route' => [[50.55573, 5.54831], [50.5541, 5.54805], [50.55337, 5.54818], [50.55306, 5.54839], [50.55279, 5.54894], [50.55244, 5.54986], [50.55245, 5.55005], [50.55264, 5.55053], [50.55279, 5.55157], [50.55294, 5.55284], [50.55319, 5.55429], [50.55336, 5.55532], [50.55357, 5.55657], [50.55373, 5.55748], [50.55372, 5.55812], [50.55358, 5.55866], [50.55364, 5.55949], [50.55354, 5.56097], [50.55329, 5.56186], [50.55323, 5.56241], [50.55348, 5.56466], [50.55345, 5.56557], [50.55323, 5.56668], [50.55318, 5.56787], [50.55316, 5.56809]],
                    'grad' => [6, 8, 9, 10, 11, 10, 9, 8, 9, 7],
                    'steep' => ['at' => [50.55358, 5.55866], 'pct' => '~11%'],
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
                    'route' => [[50.37607, 5.87635], [50.37562, 5.87507], [50.37581, 5.87496], [50.37649, 5.87599], [50.37772, 5.88222], [50.37909, 5.889], [50.37956, 5.89538], [50.38177, 5.89828], [50.38377, 5.89968], [50.38502, 5.90168], [50.38572, 5.90459], [50.38555, 5.91477], [50.38594, 5.91773], [50.38679, 5.91984], [50.38873, 5.92195], [50.39616, 5.92631], [50.39779, 5.92894], [50.39929, 5.93248], [50.39966, 5.93345], [50.40096, 5.93566], [50.40342, 5.93773], [50.40489, 5.93981], [50.40639, 5.9435], [50.40778, 5.94564], [50.40987, 5.94666], [50.41622, 5.94621], [50.42327, 5.94568], [50.42495, 5.94602], [50.42748, 5.94905], [50.43026, 5.95383], [50.43335, 5.95802], [50.43566, 5.96229], [50.43621, 5.96331], [50.43772, 5.96491], [50.43955, 5.96542], [50.44138, 5.96473], [50.44558, 5.96159], [50.44863, 5.95825], [50.45217, 5.95711], [50.45307, 5.95661], [50.4533, 5.9569], [50.45397, 5.95693], [50.45705, 5.95663], [50.4593, 5.95658], [50.46281, 5.95834], [50.46482, 5.96077], [50.46627, 5.96352], [50.46856, 5.96804], [50.46879, 5.96843], [50.47117, 5.97136], [50.47425, 5.97323], [50.47445, 5.97327], [50.47476, 5.97508], [50.47542, 5.97394], [50.47745, 5.97543], [50.47883, 5.97707], [50.48107, 5.98187], [50.48237, 5.98524], [50.48307, 5.98707]],
                    'grad' => [2, 2, 1, 1, 2, 2, 2, 2, 1, 1, 3],
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
                    'photos' => [
                        ['sm' => '/media/botrange-sm.jpg', 'lg' => '/media/botrange.jpg', 'credit' => 'Trougnouf (Benoit Brummer)', 'creditUrl' => 'https://commons.wikimedia.org/wiki/User:Trougnouf', 'license' => 'CC BY 4.0', 'source' => 'https://commons.wikimedia.org/wiki/File:Signal_de_Botrange_(DSCF6640).jpg'],
                        self::wc('1031346 Botrange 700m.jpg', 'Wikoli', 'Wikoli', 'CC BY-SA 3.0'),
                        self::wc('SignalDeBotrange6mTower.jpg', 'David Edgar', 'David Edgar', 'CC BY-SA 3.0'),
                    ],
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
                    'photos' => [
                        ['sm' => '/media/stavelot-abbey-sm.jpg', 'lg' => '/media/stavelot-abbey.jpg', 'credit' => 'Nenea hartia', 'creditUrl' => 'https://commons.wikimedia.org/wiki/User:Nenea_hartia', 'license' => 'CC BY-SA 4.0', 'source' => 'https://commons.wikimedia.org/wiki/File:Abbaye_de_Stavelot.01.jpg'],
                        self::wc('Abbaye de Stavelot 03.jpg', 'FrDr', 'FrDr', 'CC BY-SA 4.0'),
                    ],
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
            $skipped = [];
            foreach (self::pins() as $pin) {
                $type = ItemType::fromParam($pin['letter']);
                $this->vocabulary->assertValid($type, $pin['attributes']);

                if ($this->duplicatesNonManualItem($pin['name'], $pin['letter'])) {
                    $skipped[] = $pin['name'];
                    continue;
                }

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
        if ([] !== $skipped) {
            $io->note(sprintf(
                'Skipped %d pin(s) that duplicate an existing non-manual item (same name + letter): %s',
                \count($skipped),
                implode(', ', $skipped),
            ));
        }
        $io->success(sprintf('Seeded %d manual demo pin(s) across %d letter(s).', array_sum($counts), \count($counts)));

        return Command::SUCCESS;
    }

    /**
     * Guards against re-seeding a manual duplicate of a place the OSM/pivot/…
     * harvest already imported under a different source (C4-T11 dedup fix —
     * items 11018/11019/11021 duplicated osm rows 3597/3602/3353 before this
     * guard existed). Matches on (name, letter): the same real-world place,
     * re-seeded under `source = manual`, would otherwise double/triple-render
     * on the map. Only non-manual rows count as a collision — re-running the
     * seeder must still upsert its own previously-seeded manual rows.
     */
    private function duplicatesNonManualItem(string $name, string $letter): bool
    {
        $match = $this->db->fetchOne(
            "SELECT 1 FROM item WHERE name = :name AND letter = :letter AND source != 'manual' LIMIT 1",
            ['name' => $name, 'letter' => $letter],
        );

        return false !== $match;
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
