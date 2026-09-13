<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog;

use App\Media\Commons\CommonsFile;
use App\Media\Commons\CommonsPhotoAdmission;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * A seasonal result, simulated, so the shape can be argued about before the
 * ballot exists.
 *
 * **Why fabricate anything.** `route_vote` is empty and will stay empty until
 * the ballot opens, so a results page built on real counts would be a blank
 * screen in every region and could not answer the question it exists to
 * answer: what should this page show, and what makes a ranking believable.
 * The demo ballot at `/vote` was built for the same reason (owner 2026-09-12:
 * "for now like in the demo we need to simulate a page, this can also help us
 * design the voting specs").
 *
 * **Real names, invented counts.** Every row here is a row a rider could open
 * on the map, which is what makes the layout worth judging. Only the tallies
 * are made up, and they are derived from the row's own id, so the same place
 * shows the same number on every load and nobody mistakes a reshuffle for a
 * vote. `simulated` rides on every row so a template cannot forget to say so.
 *
 * @phpstan-type Photo array{sm: string, credit: ?string, creditUrl: ?string, license: ?string}
 * @phpstan-type Ranked array{id: int, ref: ?string, name: string, kind: string, letter: string, note: ?string, photo: ?Photo, notePlaceholder: bool, photoPlaceholder: bool, commonsFile: ?string, filler: string, hue: int, votes: int, rides: int, share: int, simulated: true}
 *
 * @see docs/specs/route-domain.md §8
 *
 * @api
 */
// Not `readonly`: the one mutable field is a memo of whether the pipeline's
// table exists, asked once per request instead of once per region.
final class BestOfPreview
{
    /** A shortlist is the point: a ranking nobody reads to the end ranks nothing. */
    public const int TOP_N = 10;

    /** Where the placeholder hues start: amber, the warm end of the site's palette. */
    private const int HUE_FROM = 25;

    /** How far they run: to roughly 175, amber through olive into sage. */
    private const int HUE_RANGE = 150;

    /** What a winning entry plausibly polls in a first season, at the low end. */
    private const int LEAD_MIN = 120;

    /** How far above LEAD_MIN a winner can land, so categories do not all share a number. */
    private const int LEAD_SPREAD = 60;

    /**
     * What each place down the list keeps of the place above it.
     *
     * At 0.82 a ten-deep list runs roughly 140 down to 24: the podium is
     * clearly a podium and tenth place is clearly not, which is the shape a
     * ranking has to have before anybody can say whether it reads well.
     */
    private const float DECAY = 0.82;

    /**
     * How long a ride is, in the three lengths a rider picks between.
     *
     * Owner 2026-09-12: "short 50 km, medium 100 km, long 150 km+". So fifty
     * is where short ends, a hundred and fifty is where long begins, and
     * medium is what a hundred sits in the middle of. Metres, because that is
     * what `recommended_route.distance_m` holds.
     *
     * @var array<string, array{0: int, 1: ?int}>
     */
    public const array LENGTHS = [
        'Short' => [0, 50_000],
        'Medium' => [50_000, 150_000],
        'Long' => [150_000, null],
    ];

    /** Asked once: the answer cannot change inside a request. */
    private ?bool $hasCoverage = null;

    public function __construct(
        private readonly Connection $db,
        // The map drawer's own reader. A place's picture lives in
        // `commons_photo` and belongs to the place, so a ranking that showed
        // one from anywhere else would be a second copy to keep in step, and
        // the credit line is the half that would drift (owner 2026-09-13).
        private readonly CommonsPhotoAdmission $admission,
    ) {
    }

    /**
     * Whether the OpenStreetMap half of the atlas is here at all.
     *
     * `coverage_poi` is the Python pipeline's own DDL, so a fresh cluster and
     * every test database are without it until a harvest has run
     * (coverage-provider.md §11). Asking for it there is a fatal error, not an
     * empty list, so the arm is skipped instead.
     */
    private function hasCoverage(): bool
    {
        // Asked of Postgres, not of Doctrine's schema manager: the pipeline's
        // tables are outside the mapped schema, so `tablesExist()` answers
        // false for a table that is plainly there and the whole half of the
        // atlas silently vanished.
        return $this->hasCoverage ??= null !== $this->db->fetchOne("SELECT to_regclass('coverage_poi')");
    }

    /**
     * The votable types, in the order the ballot offers them.
     *
     * @return list<ItemType>
     */
    public static function categories(): array
    {
        return array_values(array_filter(ItemType::cases(), static fn (ItemType $t): bool => $t->isVotable()));
    }

    /**
     * One category's ranking for one scope.
     *
     * @param ?string      $countryCode  ISO 3166-1 alpha-2, or null for everywhere
     * @param list<string> $bikes        any of these, empty means any at all
     * @param list<string> $difficulties
     * @param list<string> $lengths
     *
     * @return list<Ranked>
     */
    public function ranking(ItemType $type, Season $season, ?string $countryCode = null, array $bikes = [], array $difficulties = [], ?int $regionId = null, array $lengths = []): array
    {
        $rows = ItemType::QualityRides === $type
            ? $this->routes($countryCode, $bikes, $difficulties, $regionId, $lengths)
            : $this->items($type, $countryCode, $regionId);

        // One query for the whole candidate set, not one per row. It answers
        // only "is there a picture", which is all the ranking needs; the URL
        // and its credit are read once the list is cut, from the one class
        // that builds them.
        $shot = $this->readyFiles($rows);

        $ranked = [];
        foreach ($rows as $row) {
            // The bike is BOTH: the route records which bikes it suits
            // (`attributes.bikeTypes`, "Suitable bike types") and the vote
            // records which bike the voter rated it on (RouteVote::$bikeType).
            // Picking one narrows the rows AND changes who ranked them, which
            // together is what "best on a handbike" means.
            $ranked[] = $this->tally($row, $type, $season, $bikes, $shot);
        }

        // Highest first, and by id when two land on the same number, so the
        // order is as fixed as the numbers are.
        usort($ranked, static fn (array $a, array $b): int => [$b['votes'], $a['id']] <=> [$a['votes'], $b['id']]);
        $ranked = \array_slice($ranked, 0, self::TOP_N);

        // Only the ten that survived: a photo lookup per candidate would be
        // four hundred queries to throw away three hundred and ninety of them.
        foreach ($ranked as $i => $r) {
            if (null !== $r['photo'] || null === $r['commonsFile']) {
                continue;
            }
            $ready = $this->admission->readyPhoto($r['commonsFile']);
            if (null === $ready || !\is_string($ready['sm'] ?? null)) {
                continue;
            }
            $ranked[$i]['photo'] = [
                'sm' => $ready['sm'],
                'credit' => \is_string($ready['credit'] ?? null) ? $ready['credit'] : null,
                'creditUrl' => \is_string($ready['creditUrl'] ?? null) && '' !== $ready['creditUrl'] ? $ready['creditUrl'] : null,
                'license' => \is_string($ready['license'] ?? null) ? $ready['license'] : null,
            ];
            $ranked[$i]['photoPlaceholder'] = false;
        }

        // A seeded draw decides the ORDER; it cannot decide the counts. Take
        // the top ten of a few hundred uniform draws and every row lands
        // within a few votes of the ceiling, so ten near-identical numbers
        // and ten identical bars. A real ballot has a shape: the winner
        // pulls clear, the tail thins. The curve below puts that shape back,
        // which is the thing this page exists to let somebody judge.
        $lead = self::LEAD_MIN + abs(crc32('lead:'.$type->value.':'.$season->value)) % self::LEAD_SPREAD;
        foreach ($ranked as $i => $r) {
            // Hashed, not taken from the id directly. Catalogue ids arrive in
            // blocks, so arithmetic on them moves every row by the same step
            // and the jitter meant to break up the numbers lines them up
            // instead: ten rows each showing exactly five more riders.
            $noise = abs(crc32('n:'.$season->value.':'.$r['id']));
            $votes = max(3, (int) round($lead * self::DECAY ** $i) - $noise % 6);
            $ranked[$i]['votes'] = $votes;
            // More people rode it than rated it, always: riding is the
            // precondition for voting, and most riders never vote.
            $ranked[$i]['rides'] = (int) round($votes * (1.15 + ($noise >> 5) % 90 / 100));
        }

        // Against the winner, not against the sum. A share of the ten tops
        // out near 20%, so every bar would sit near-empty and the winner
        // would look no different from the rest. Read it as "how close to
        // first place", which is the question a ranking actually poses.
        $lead = $ranked[0]['votes'] ?? 1;
        foreach ($ranked as $i => $r) {
            $ranked[$i]['share'] = (int) round(100 * $r['votes'] / max(1, $lead));
        }

        return $ranked;
    }

    /**
     * Which of these rows already have a fetched picture, by Commons file name.
     *
     * One statement for the whole candidate set. The alternative is a lookup
     * per row, which for a five-category page across a country's regions runs
     * into the thousands, all to decide the order of ten.
     *
     * @param list<array{id: int|string, name: string, attributes: string|null, ref: string|null, distance_m: int|string|null}> $rows
     *
     * @return array<string, true>
     */
    private function readyFiles(array $rows): array
    {
        $files = [];
        foreach ($rows as $row) {
            $attrs = $row['attributes'] ?? null;
            if (\is_string($attrs)) {
                /** @var array<string, mixed> $attrs */
                $attrs = (array) json_decode($attrs, true, 8, \JSON_THROW_ON_ERROR);
            }
            if (!\is_array($attrs)) {
                continue;
            }
            $file = CommonsFile::fromTags($attrs);
            if (null !== $file) {
                $files[$file] = true;
            }
        }
        if ([] === $files) {
            return [];
        }

        /** @var list<string> $ready */
        $ready = $this->db->fetchFirstColumn(
            "SELECT file FROM commons_photo WHERE state = 'ready' AND file IN (:files)",
            ['files' => array_keys($files)],
            ['files' => ArrayParameterType::STRING],
        );

        return array_fill_keys($ready, true);
    }

    /**
     * A country's regions, split into the ones with a result and the ones without.
     *
     * **A national top ten is the wrong unit for a big country.** France's
     * ranking is the Alps against the Massif Central against Brittany, which
     * flattens into one list that tells a rider near Annecy nothing (owner
     * 2026-09-12). The unit a rider rides in is the region.
     *
     * The empty ones are not hidden: they go at the foot of the page, because
     * "nobody has voted here yet" is an invitation and a silent omission is
     * not. In the preview every region with rows has a result, so which side a
     * region lands on says whether the catalogue reaches it at all, which is
     * the honest version of the same signal.
     *
     * @param list<string> $bikes
     * @param list<string> $difficulties
     * @param list<string> $lengths
     *
     * @return array{ranked: list<array{slug: string, name: string, top: list<Ranked>}>, quiet: list<array{slug: string, name: string}>}
     */
    public function byRegion(ItemType $type, Season $season, string $countryCode, array $bikes = [], array $difficulties = [], int $perRegion = self::TOP_N, array $lengths = []): array
    {
        $ranked = [];
        $quiet = [];
        foreach ($this->regionsOf($countryCode) as $region) {
            $top = \array_slice(
                $this->ranking($type, $season, $countryCode, $bikes, $difficulties, (int) $region['id'], $lengths),
                0,
                $perRegion,
            );
            $entry = ['slug' => (string) $region['slug'], 'name' => (string) $region['name']];
            if ([] === $top) {
                $quiet[] = $entry;

                continue;
            }
            $ranked[] = $entry + ['top' => $top];
        }

        return ['ranked' => $ranked, 'quiet' => $quiet];
    }

    /**
     * @return list<array{id: int|string, slug: string, name: string}>
     */
    private function regionsOf(string $countryCode): array
    {
        // Operational regions only. A country also carries its own L2 outline
        // row, which has no page and would sit in the list as "France" inside
        // France (catalog-data-model.md §2.4).
        /** @var list<array{id: int|string, slug: string, name: string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, slug, name FROM region
              WHERE country_code = :cc AND '.OperationalRegions::predicate().'
              ORDER BY name',
            ['cc' => $countryCode],
        );

        return $rows;
    }

    /**
     * A believable tally from nothing but the row and the round.
     *
     * Deterministic on purpose: a reload that reshuffled the order would teach
     * a reader that the numbers move, which is the one thing this page must
     * not imply while it is a preview.
     *
     * @return Ranked
     */
    /**
     * @param array{id: int|string, name: string, attributes?: string|array<string, mixed>|null, ref?: string|null, distance_m?: int|string|null} $row
     *
     * @return Ranked
     */
    /**
     * @param array{id: int|string, name: string, attributes?: string|array<string, mixed>|null, ref?: string|null, distance_m?: int|string|null} $row
     * @param list<string>                                                                                                                        $bikes
     * @param array<string, true>                                                                                                                 $shot  files already fetched
     *
     * @return Ranked
     */
    private function tally(array $row, ItemType $type, Season $season, array $bikes = [], array $shot = []): array
    {
        $id = (int) $row['id'];
        $seed = crc32($type->value.':'.$season->value.':'.([] === $bikes ? 'any' : implode('+', $bikes)).':'.($row['ref'] ?? (string) $id));
        // An ordering score, not a displayed count: ranking() replaces the
        // number once the list is cut, so the tail cannot bunch at the top.
        $votes = 3 + $seed % 58;
        // A curated row outranks a bare OpenStreetMap one, other things equal.
        // Not a thumb on the scale for the look of it: somebody bothered to
        // adopt that place into the catalogue, write a note and add a
        // photograph, which is the same kind of attention a vote is. It also
        // keeps the preview honest about itself, since a podium that never
        // showed a picture would not be showing what the page becomes.
        if (!\is_string($row['ref'] ?? null) || '' === $row['ref']) {
            $votes += 30;
        }
        $rides = $votes + ($seed >> 8) % 40;     // never fewer people than voters

        $attrs = $row['attributes'] ?? null;
        if (\is_string($attrs)) {
            /** @var array<string, mixed> $attrs */
            $attrs = (array) json_decode($attrs, true, 8, \JSON_THROW_ON_ERROR);
        }
        $attrs = \is_array($attrs) ? $attrs : [];
        // The one fact a route keeps in a column rather than in its
        // attributes, and the first thing a rider reads about one.
        if (is_numeric($row['distance_m'] ?? null)) {
            $attrs['distanceM'] = $row['distance_m'];
        }

        // A coverage row hands its OpenStreetMap tags over as `attributes`, so
        // `wikimedia_commons` and `image` arrive here unchanged and the file
        // name needs no second query to find.
        $file = CommonsFile::fromTags($attrs);
        // A photographed place outranks an unphotographed one, for the reason
        // the curated bonus exists: a podium of three empty panels shows the
        // layout but not the page, and the page is what there is to judge.
        // It cannot invent a winner either, since it only moves rows that a
        // real picture is already sitting in the store for.
        if (null !== $file && isset($shot[$file])) {
            $votes += 20;
        }

        return [
            'id' => $id,
            // A coverage row has no catalogue id a link could use, so it
            // travels by its OSM ref, the only per-POI key it has.
            'ref' => \is_string($row['ref'] ?? null) && '' !== $row['ref'] ? $row['ref'] : null,
            'name' => $row['name'],
            'kind' => $type->value,
            'letter' => $type->letter(),
            // One line of what it is, where the catalogue has one. Not every
            // row does, and an invented sentence would be the one thing on
            // this page that could mislead about a real place.
            'note' => $note = self::describe($type, $attrs, null !== ($row['ref'] ?? null)),
            'photo' => $photo = self::photo($attrs),
            // A card with no picture and no line collapses, and a layout you
            // cannot see is a layout you cannot judge, which is this page's
            // whole job (owner 2026-09-12). So filler stands in, and says so:
            // the flags let the template draw it as filler rather than pass it
            // off as a photograph of this place or a sentence about it.
            'notePlaceholder' => null === $note,
            'photoPlaceholder' => null === $photo,
            'commonsFile' => $file,
            // Always built, never always shown. Down the list it stands in for
            // a missing note; on the podium it runs under the stored fact,
            // because a card carrying two words ("castle") has no body and a
            // body is the part of this layout still to be judged.
            'filler' => self::filler($seed),
            // One hue per place, held steady by the same seed, and kept inside
            // the amber-to-olive band the rest of the site is built from. The
            // picture panel is the largest thing on a podium card, so three
            // identical grey rectangles say nothing about how the row reads,
            // while a full circle of hues puts a purple block on cream paper.
            'hue' => self::HUE_FROM + abs($seed) % self::HUE_RANGE,
            'votes' => $votes,
            'rides' => $rides,
            'share' => 0,
            'simulated' => true,
        ];
    }

    /**
     * One line about what this thing is, from what the row already holds.
     *
     * **Never invented.** On a page whose tallies are made up, a sentence
     * about a real castle is the one thing that could actually mislead. Every
     * word here is a value already stored: a note somebody wrote, a climb's
     * measured gradient, a route's distance, or the OpenStreetMap tag that
     * says what kind of thing it is.
     *
     * A climb and a route describe themselves in numbers because that is what
     * a rider is choosing between; a castle describes itself in words because
     * its numbers say nothing.
     *
     * @param array<string, mixed> $attrs
     */
    private static function describe(ItemType $type, array $attrs, bool $fromCoverage): ?string
    {
        if (!$fromCoverage && ItemType::Climbs === $type) {
            return self::join([
                self::km($attrs['length'] ?? null),
                \is_string($attrs['avgGradient'] ?? null) ? $attrs['avgGradient'] : null,
                is_numeric($attrs['gain'] ?? null) ? sprintf('%d m up', (int) $attrs['gain']) : null,
                \is_string($attrs['surface'] ?? null) ? $attrs['surface'] : null,
            ]);
        }
        if (!$fromCoverage && ItemType::QualityRides === $type) {
            return self::join([
                self::km($attrs['distanceM'] ?? null),
                \is_array($attrs['difficulty'] ?? null) && \is_string($attrs['difficulty']['label'] ?? null)
                    ? $attrs['difficulty']['label'] : null,
                \is_string($attrs['dominantSurface'] ?? null) ? $attrs['dominantSurface'] : null,
            ]);
        }

        foreach (['note', 'type'] as $key) {
            $v = $attrs[$key] ?? null;
            if (\is_string($v) && '' !== trim($v)) {
                return mb_substr(trim($v), 0, 140);
            }
        }

        // An OpenStreetMap row says what it is in its own tags, and nowhere
        // else: `historic=memorial`, `natural=peak` with an elevation.
        $kind = null;
        foreach (['historic', 'tourism', 'natural', 'amenity', 'man_made', 'building', 'leisure'] as $key) {
            $v = $attrs[$key] ?? null;
            if (\is_string($v) && '' !== $v && 'yes' !== $v) {
                $kind = str_replace('_', ' ', $v);
                break;
            }
        }

        return self::join([
            $kind,
            is_numeric($attrs['ele'] ?? null) ? sprintf('%d m', (int) $attrs['ele']) : null,
        ]);
    }

    /**
     * Stand-in prose, where the catalogue holds none.
     *
     * Latin on purpose. Plausible English under a real castle would be a
     * sentence a reader could believe, and this page must never invent a
     * claim about a real place; nobody mistakes this for one.
     */
    private static function filler(int $seed): string
    {
        $clauses = [
            'Lorem ipsum dolor sit amet, consectetur adipiscing elit.',
            'Sed do eiusmod tempor incididunt ut labore et dolore magna.',
            'Ut enim ad minim veniam, quis nostrud exercitation ullamco.',
            'Duis aute irure dolor in reprehenderit in voluptate velit.',
            'Excepteur sint occaecat cupidatat non proident, sunt in culpa.',
            'Nemo enim ipsam voluptatem quia voluptas sit aspernatur.',
            'Neque porro quisquam est qui dolorem ipsum quia dolor sit.',
            'At vero eos et accusamus et iusto odio dignissimos ducimus.',
        ];

        // Two or three clauses, not one. A single line under a photograph
        // leaves the card as empty as no line at all, and the length of the
        // real text is part of what a layout has to survive.
        $n = \count($clauses);
        $out = [];
        for ($i = 0, $take = 2 + abs($seed >> 4) % 2; $i < $take; ++$i) {
            $out[] = $clauses[abs($seed >> (3 * $i)) % $n];
        }

        return implode(' ', array_unique($out));
    }

    /** @param list<?string> $parts */
    private static function join(array $parts): ?string
    {
        $kept = array_values(array_filter($parts, static fn (?string $p): bool => null !== $p && '' !== $p));

        return [] === $kept ? null : implode(' · ', $kept);
    }

    private static function km(mixed $metres): ?string
    {
        if (!is_numeric($metres) || $metres <= 0) {
            return null;
        }
        $km = (float) $metres / 1000;

        return sprintf($km < 10 ? '%.1f km' : '%d km', $km < 10 ? $km : round($km));
    }

    /**
     * The small rendering and its credit, or nothing.
     *
     * The credit travels WITH the URL: these are CC BY-SA files, so a card
     * that shows the picture and drops the attribution is a licence breach,
     * not a layout choice (photo-uploads.md §5f).
     *
     * @param array<string, mixed> $attrs
     *
     * @return Photo|null
     */
    private static function photo(array $attrs): ?array
    {
        $p = $attrs['photo'] ?? null;
        if (!\is_array($p) && \is_array($attrs['photos'] ?? null)) {
            $p = $attrs['photos'][0] ?? null;
        }
        if (!\is_array($p) || !\is_string($p['sm'] ?? null) || '' === $p['sm']) {
            return null;
        }

        return [
            'sm' => $p['sm'],
            'credit' => \is_string($p['credit'] ?? null) ? $p['credit'] : null,
            'creditUrl' => \is_string($p['creditUrl'] ?? null) ? $p['creditUrl'] : null,
            'license' => \is_string($p['license'] ?? null) ? $p['license'] : null,
        ];
    }

    /**
     * Everything of this kind a rider could rate here.
     *
     * **Both halves of the atlas, because a region holds four or five curated
     * rows and a top ten needs more than that** (owner 2026-09-12). The
     * curated `item` rows are ours, with photographs and notes; the rest are
     * named OpenStreetMap places from `coverage_poi`, which is where the
     * hundreds of thousands are. A coverage row a curated row already claims
     * is dropped, the same exclusivity the map applies, or the same castle
     * would rank twice under two names.
     *
     * @return list<array{id: int|string, name: string, attributes: string|null, ref: string|null, distance_m: int|string|null}>
     */
    private function items(ItemType $type, ?string $countryCode, ?int $regionId = null): array
    {
        $letter = $type->letter();
        $sql = 'SELECT id, name, attributes, NULL AS ref, NULL AS distance_m FROM item
                 WHERE letter = :letter
                   AND state IN '.ItemState::servedSqlTuple()."
                   AND name <> ''";
        $params = ['letter' => $letter];
        if (null !== $countryCode) {
            $sql .= ' AND country_code = :cc';
            $params['cc'] = $countryCode;
        }
        if (null !== $regionId) {
            $sql .= ' AND region_id = :rid';
            $params['rid'] = $regionId;
        }

        $cov = "SELECT cp.id, cp.name, cp.tags AS attributes, cp.ref, NULL AS distance_m
                  FROM coverage_poi cp
                 WHERE cp.letter = :letter
                   AND cp.name IS NOT NULL AND cp.name <> ''
                   AND NOT EXISTS (
                       SELECT 1 FROM item i
                        WHERE cp.ref IN (i.source_ref, i.osm_ref)
                          AND i.state IN ".ItemState::servedSqlTuple().')';
        if (null !== $countryCode) {
            $cov .= ' AND cp.country_code = :cc';
        }
        if (null !== $regionId) {
            $cov .= ' AND cp.region_id = :rid';
        }

        /** @var list<array{id: int|string, name: string, attributes: string|null, ref: string|null, distance_m: int|string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            $this->hasCoverage()
                ? '('.$sql.' ORDER BY id LIMIT 200) UNION ALL ('.$cov.' ORDER BY cp.id LIMIT 200)'
                : $sql.' ORDER BY id LIMIT 200',
            $params,
        );

        return $rows;
    }

    /**
     * /**
     * Routes, narrowed by what the route itself records.
     *
     * Both filters are the route's own attributes: difficulty as
     * `{label, score}` (DifficultyVocabulary) and `bikeTypes` as the list a
     * proposer ticked under "Suitable bike types".
     *
     * **A route with no bike types recorded stays in.** Nobody saying which
     * bikes suit it is not the same as saying none do, and excluding those
     * would empty the list wherever the field has not been filled in yet,
     * which is most of the catalogue today.
     *
     * @return list<array{id: int|string, name: string, attributes: string|null, ref: string|null}>
     */
    /**
     * @param list<string> $bikes
     * @param list<string> $difficulties
     * @param list<string> $lengths
     *
     * @return list<array{id: int|string, name: string, attributes: string|null, ref: string|null, distance_m: int|string|null}>
     */
    private function routes(?string $countryCode, array $bikes, array $difficulties, ?int $regionId = null, array $lengths = []): array
    {
        $sql = 'SELECT r.id, r.name, r.attributes, NULL AS ref, r.distance_m FROM recommended_route r
                 WHERE r.state IN '.ItemState::servedSqlTuple()."
                   AND r.name <> ''";
        $params = [];
        $types = [];
        if (null !== $countryCode) {
            $sql .= ' AND EXISTS (SELECT 1 FROM region g WHERE g.id = r.region_id AND g.country_code = :cc)';
            $params['cc'] = $countryCode;
        }
        if (null !== $regionId) {
            $sql .= ' AND r.region_id = :rid';
            $params['rid'] = $regionId;
        }
        // Any of the chosen bikes, not all of them: a rider ticking Gravel and
        // MTB is asking for routes that suit either.
        if ([] !== $bikes) {
            $ors = [];
            foreach ($bikes as $i => $b) {
                $ors[] = "r.attributes->'bikeTypes' @> to_jsonb(:bike$i::text)";
                $params["bike$i"] = $b;
            }
            $sql .= " AND (r.attributes->'bikeTypes' IS NULL
                        OR jsonb_array_length(r.attributes->'bikeTypes') = 0
                        OR ".implode(' OR ', $ors).')';
        }
        if ([] !== $difficulties) {
            $sql .= " AND r.attributes->'difficulty'->>'label' IN (:diffs)";
            $params['diffs'] = $difficulties;
            $types['diffs'] = ArrayParameterType::STRING;
        }
        if ([] !== $lengths) {
            $bands = [];
            foreach ($lengths as $i => $name) {
                if (!isset(self::LENGTHS[$name])) {
                    continue;
                }
                [$from, $to] = self::LENGTHS[$name];
                $params["lf$i"] = $from;
                if (null === $to) {
                    $bands[] = "r.distance_m >= :lf$i";

                    continue;
                }
                $params["lt$i"] = $to;
                $bands[] = "(r.distance_m >= :lf$i AND r.distance_m < :lt$i)";
            }
            if ([] !== $bands) {
                $sql .= ' AND ('.implode(' OR ', $bands).')';
            }
        }

        /** @var list<array{id: int|string, name: string, attributes: string|null, ref: string|null, distance_m: int|string|null}> $rows */
        $rows = $this->db->fetchAllAssociative($sql.' ORDER BY r.id LIMIT 200', $params, $types);

        return $rows;
    }
}
