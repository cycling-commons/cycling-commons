<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Media\Commons;

use App\Media\LicenceUrls;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The only class that talks to Wikimedia: a file's credit and licence, a
 * Wikidata item's P18, and for the town card its sitelinks, a Wikipedia
 * page's first paragraph, and the cycling races Wikidata ties to a place.
 *
 * Wikimedia asks every client to identify itself, and an anonymous batch is
 * rate-limited to 429 within a request or two. That is not theoretical: the
 * measurement run behind this feature failed exactly that way on 2026-08-24
 * before a User-Agent was set.
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
final readonly class CommonsApi
{
    public function __construct(
        private HttpClientInterface $http,
        #[Autowire('%env(APP_COMMONS_USER_AGENT)%')]
        private string $userAgent = '',
    ) {
    }

    /** Titles per camera request. Commons accepts 50 without a bot flag; 20 keeps each request small. */
    public const int CAMERA_BATCH_MAX = 20;

    /** Fewer decimals than this in either axis is a scene centre, not a camera. */
    private const int CAMERA_MIN_DECIMALS = 3;

    /**
     * Credit, licence, a 1400px rendering URL and where the camera stood, or
     * null when the file is not one we may republish.
     *
     * The camera is null when Commons records none (see camera()).
     *
     * @return array{thumbUrl: string, credit: string, creditUser: ?string, license: string, cameraLat: ?float, cameraLng: ?float}|null
     *
     * @throws CommonsUnavailable
     */
    public function fileInfo(string $file): ?array
    {
        $data = $this->get('https://commons.wikimedia.org/w/api.php', [
            'action' => 'query',
            'format' => 'json',
            'formatversion' => '2',
            'titles' => 'File:'.$file,
            'prop' => 'imageinfo|coordinates',
            'iiprop' => 'url|extmetadata',
            // The file's primary coordinate and its type, so camera() can tell
            // where the camera stood from where the subject is.
            'coprimary' => 'primary',
            'coprop' => 'type|globe',
            // Ask Commons to render the size we would produce anyway. Originals
            // include 200 MB TIFFs, and this bounds the download before
            // PhotoProcessor::MAX_BYTES ever has to refuse one.
            'iiurlwidth' => '1400',
        ]);

        $page = $data['query']['pages'][0] ?? null;
        $info = \is_array($page) ? ($page['imageinfo'][0] ?? null) : null;
        if (!\is_array($info) || !\is_string($info['thumburl'] ?? null)) {
            return null;
        }

        $meta = \is_array($info['extmetadata'] ?? null) ? $info['extmetadata'] : [];
        $licenseRaw = $meta['LicenseShortName']['value'] ?? null;
        $license = \is_string($licenseRaw) ? trim($licenseRaw) : '';
        // Fail closed, and the test is deliberately "can we point at its deed?"
        // rather than a second list of names: a licence we cannot identify is
        // one we cannot attribute, and a file we cannot attribute is one we do
        // not republish (LicenceUrls).
        if (null === LicenceUrls::urlFor($license)) {
            return null;
        }

        $artistRaw = $meta['Artist']['value'] ?? null;
        $artist = \is_string($artistRaw) ? $artistRaw : '';

        $camera = \is_array($page) ? self::camera($page) : null;

        return [
            'thumbUrl' => $info['thumburl'],
            'credit' => self::plainCredit($artist),
            'creditUser' => self::commonsUser($artist),
            'license' => $license,
            'cameraLat' => $camera[0] ?? null,
            'cameraLng' => $camera[1] ?? null,
        ];
    }

    /**
     * Where the camera stood, for up to CAMERA_BATCH_MAX files in one request.
     *
     * POST, because twenty long file names in a query string run past what
     * some proxies accept. Keyed by the name asked for, whatever Commons
     * normalised it to; a file Commons does not have maps to null.
     *
     * @param list<string> $files names without the `File:` prefix
     *
     * @return array<string, array{0: float, 1: float}|null>
     *
     * @throws CommonsUnavailable
     */
    public function cameraLocations(array $files): array
    {
        if (\count($files) > self::CAMERA_BATCH_MAX) {
            throw new \InvalidArgumentException(sprintf('At most %d files per request, got %d.', self::CAMERA_BATCH_MAX, \count($files)));
        }
        if ([] === $files) {
            return [];
        }

        $form = [
            'action' => 'query',
            'format' => 'json',
            'formatversion' => '2',
            'titles' => implode('|', array_map(static fn (string $f): string => 'File:'.$f, $files)),
            'prop' => 'coordinates',
            'coprimary' => 'primary',
            'coprop' => 'type|globe',
            'colimit' => 'max',
        ];

        $normalised = [];
        $cameras = [];
        // Commons pages a long answer with `continue`. One primary coordinate
        // per file and a limit of 500 make that unreachable for 20 files, but
        // following it costs nothing and a truncated answer would read as "no
        // camera" for the files it cut.
        for ($round = 0, $continue = []; $round < 5; ++$round) {
            $data = $this->post('https://commons.wikimedia.org/w/api.php', $form + $continue);
            /** @var list<array{from?: mixed, to?: mixed}> $norm */
            $norm = \is_array($data['query']['normalized'] ?? null) ? $data['query']['normalized'] : [];
            foreach ($norm as $n) {
                if (\is_string($n['from'] ?? null) && \is_string($n['to'] ?? null)) {
                    $normalised[$n['from']] = $n['to'];
                }
            }
            /** @var list<mixed> $pages */
            $pages = \is_array($data['query']['pages'] ?? null) ? $data['query']['pages'] : [];
            foreach ($pages as $page) {
                if (\is_array($page) && \is_string($page['title'] ?? null)) {
                    $cameras[$page['title']] ??= self::camera($page);
                }
            }
            if (!\is_array($data['continue'] ?? null)) {
                break;
            }
            /** @var array<string, string> $next */
            $next = array_filter($data['continue'], is_string(...));
            $continue = $next;
        }

        $out = [];
        foreach ($files as $file) {
            $title = 'File:'.$file;
            $out[$file] = $cameras[$normalised[$title] ?? $title] ?? null;
        }

        return $out;
    }

    /**
     * The camera point of one API page, or null.
     *
     * Commons stores two kinds of coordinate on a file: `camera` from
     * {{Location}}, where the photographer stood, and `object` from {{Object
     * location}}, where the subject is. Only the first says where a rider
     * would have to stand to see this, so only a primary coordinate of type
     * camera on Earth counts. A camera point with fewer than
     * CAMERA_MIN_DECIMALS decimals in either axis is refused too: a satellite
     * scene of Etna is filed at 37.7, 15, which is a scene centre, not a place
     * anybody stood (scenic-views.md §2).
     *
     * @param array<array-key, mixed> $page
     *
     * @return array{0: float, 1: float}|null
     */
    private static function camera(array $page): ?array
    {
        $coordinates = \is_array($page['coordinates'] ?? null) ? $page['coordinates'] : [];
        foreach ($coordinates as $c) {
            if (!\is_array($c) || 'camera' !== ($c['type'] ?? null)) {
                continue;
            }
            if (\array_key_exists('primary', $c) && true !== $c['primary'] && '' !== $c['primary']) {
                continue;
            }
            if (\is_string($c['globe'] ?? null) && 'earth' !== strtolower($c['globe'])) {
                continue;
            }
            $lat = $c['lat'] ?? null;
            $lng = $c['lon'] ?? null;
            if (!(\is_int($lat) || \is_float($lat)) || !(\is_int($lng) || \is_float($lng))) {
                continue;
            }
            if (abs($lat) > 90 || abs($lng) > 180) {
                continue;
            }
            if (self::decimals((float) $lat) < self::CAMERA_MIN_DECIMALS || self::decimals((float) $lng) < self::CAMERA_MIN_DECIMALS) {
                continue;
            }

            return [(float) $lat, (float) $lng];
        }

        return null;
    }

    /**
     * Decimal places a coordinate was written with, as far as JSON kept them.
     *
     * A trailing zero does not survive JSON (50.100 arrives as 50.1), so a
     * real camera at exactly 50.100 is refused. That costs one file in a
     * thousand per axis and is the cheaper mistake.
     */
    private static function decimals(float $value): int
    {
        $text = rtrim(number_format(abs($value), 7, '.', ''), '0');
        $dot = strpos($text, '.');

        return false === $dot ? 0 : \strlen($text) - $dot - 1;
    }

    /**
     * The Commons file named by a Wikidata item's P18, or null when it has none.
     *
     * @throws CommonsUnavailable
     */
    public function wikidataImage(string $qid): ?string
    {
        $data = $this->get('https://www.wikidata.org/w/api.php', [
            'action' => 'wbgetclaims',
            'format' => 'json',
            'entity' => $qid,
            'property' => 'P18',
        ]);

        $value = $data['claims']['P18'][0]['mainsnak']['datavalue']['value'] ?? null;

        return \is_string($value) && '' !== trim($value) ? str_replace('_', ' ', trim($value)) : null;
    }

    /**
     * Labels and Wikipedia page titles per item, in the languages asked for.
     *
     * @param list<string> $qids  at most 50, Wikidata's own batch cap
     * @param list<string> $langs two-letter codes in preference order, e.g. ['nl', 'en']
     *
     * @return array<string, array{label: ?string, label_en: ?string, links: array<string, string>}> qid => first label found, the English one, and lang => page title
     *
     * @throws CommonsUnavailable
     */
    public function entities(array $qids, array $langs): array
    {
        if ([] === $qids) {
            return [];
        }
        $data = $this->get('https://www.wikidata.org/w/api.php', [
            'action' => 'wbgetentities',
            'format' => 'json',
            'ids' => implode('|', \array_slice($qids, 0, 50)),
            'props' => 'labels|sitelinks',
            'languages' => implode('|', $langs),
            'sitefilter' => implode('|', array_map(static fn (string $l): string => $l.'wiki', $langs)),
        ]);

        $out = [];
        /** @var array<string, array{labels?: array<string, array{value?: mixed}>, sitelinks?: array<string, array{title?: mixed}>}> $entities */
        $entities = \is_array($data['entities'] ?? null) ? $data['entities'] : [];
        foreach ($entities as $qid => $entity) {
            $label = null;
            $links = [];
            foreach ($langs as $lang) {
                $value = $entity['labels'][$lang]['value'] ?? null;
                if (null === $label && \is_string($value) && '' !== $value) {
                    $label = mb_substr($value, 0, 160);
                }
                $title = $entity['sitelinks'][$lang.'wiki']['title'] ?? null;
                if (\is_string($title) && '' !== $title) {
                    $links[$lang] = $title;
                }
            }
            $en = $entity['labels']['en']['value'] ?? null;
            $out[(string) $qid] = ['label' => $label, 'label_en' => \is_string($en) && '' !== $en ? mb_substr($en, 0, 160) : null, 'links' => $links];
        }

        return $out;
    }

    /** Longest extract the town card shows; the REST summary is a paragraph, and a paragraph is enough. */
    public const int EXTRACT_MAX = 700;

    /**
     * The first paragraph of one Wikipedia page, plain text, with the page URL.
     * Null when that edition has no such page.
     *
     * @return array{title: string, extract: string, url: string}|null
     *
     * @throws CommonsUnavailable
     */
    public function pageSummary(string $lang, string $title): ?array
    {
        if (1 !== preg_match('~^[a-z]{2}$~', $lang)) {
            throw new \InvalidArgumentException('Not a Wikipedia language code: '.$lang);
        }
        $url = sprintf('https://%s.wikipedia.org/api/rest_v1/page/summary/%s', $lang, rawurlencode(str_replace(' ', '_', $title)));
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => $this->userAgent, 'Accept' => 'application/json'],
                'timeout' => 15,
                'max_duration' => 30,
            ]);
            $status = $response->getStatusCode();
            if (404 === $status) {
                return null;
            }
            if (200 !== $status) {
                throw new CommonsUnavailable('http_'.$status);
            }
            $data = $response->toArray();
        } catch (CommonsUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CommonsUnavailable($e->getMessage());
        }

        $extract = $data['extract'] ?? null;
        $pageTitle = $data['title'] ?? $title;
        $pageUrl = $data['content_urls']['desktop']['page'] ?? null;
        if (!\is_string($extract) || !\is_string($pageTitle)) {
            return null;
        }
        // Never a URL we did not expect: this ends in an <a href> on the map.
        if (!\is_string($pageUrl) || !str_starts_with($pageUrl, sprintf('https://%s.wikipedia.org/', $lang))) {
            $pageUrl = sprintf('https://%s.wikipedia.org/wiki/%s', $lang, rawurlencode(str_replace(' ', '_', $pageTitle)));
        }

        return [
            'title' => mb_substr(trim($pageTitle), 0, 240),
            'extract' => self::clip(trim(preg_replace('~\s+~u', ' ', $extract) ?? $extract)),
            'url' => $pageUrl,
        ];
    }

    /**
     * Cycling races and routes that start, finish or pass at a place, per
     * Wikidata. Editions carry the start (P1427) and destination (P1444)
     * points, so they are grouped by the race they are editions of, kept only
     * when that race is itself a kind of cycling race (Q15091377). An edition
     * that is PART OF a bigger one (P361) groups under that one's race
     * instead, when that race is a stage race, a Grand Tour, a world
     * championship (Q1344963) or a national championship (Q2306612): the
     * men's, women's and under-23 road races of the 2021 Worlds become one
     * line, and a Tour de France stage becomes "Tour de France, a stage starts
     * here" rather than a dropped "plain stage" row (owner 2026-09-07: "you
     * can combine these, they are all the same year"). A season series (UCI
     * World Tour) is not such a parent, so a Tour of Flanders stays itself.
     * Editions are counted per parent, so those three Worlds races are one
     * edition, not three. Every discipline counts: the
     * sport is any subclass of cycle sport (Q53121), so road, gravel, mountain
     * bike, cyclo-cross, track and BMX all qualify. A signed cycling route
     * (Q102307360) or mountain biking route (Q71716093) that names the place
     * is listed as itself. Labels are not asked for here: the label service
     * doubles the query's cost, and entities() fetches them in one batch.
     *
     * @return list<array{qid: string, rels: list<string>, n: int, last: ?int}> newest last-edition first, at most 8; a rel is start|finish|via, prefixed stage- when the edition was a stage of the race
     *
     * @throws CommonsUnavailable
     */
    public function cyclingEventsAt(string $qid): array
    {
        if (1 !== preg_match('~^Q\d+$~', $qid)) {
            throw new \InvalidArgumentException('Not a Wikidata id: '.$qid);
        }
        $query = <<<SPARQL
            SELECT ?grp ?rel ?stage (COUNT(DISTINCT ?ed) AS ?n) (MAX(?when) AS ?last) WHERE {
              VALUES ?rel { wdt:P1427 wdt:P1444 wdt:P2825 }
              ?item ?rel wd:{$qid} .
              {
                ?item wdt:P641 ?sport . ?sport wdt:P279* wd:Q53121 .
                ?item wdt:P31 ?race .
                OPTIONAL { ?race wdt:P31 ?kind . ?kind wdt:P279* wd:Q15091377 . BIND(true AS ?raceOk) }
                OPTIONAL { ?item wdt:P361 ?parent . ?parent wdt:P31 ?series . ?series wdt:P31 ?sk .
                           FILTER(?sk IN (wd:Q1344963, wd:Q2306612) || EXISTS { ?sk wdt:P279* wd:Q15091377 }) }
                FILTER(BOUND(?raceOk) || BOUND(?series))
                BIND(COALESCE(?series, ?race) AS ?grp)
                BIND(COALESCE(?parent, ?item) AS ?ed)
                BIND(IF(BOUND(?series) && !BOUND(?raceOk) && COALESCE(!(?sk IN (wd:Q1344963, wd:Q2306612)), false), true, false) AS ?stage)
              }
              UNION
              { VALUES ?routeRoot { wd:Q102307360 wd:Q71716093 }
                ?item wdt:P31 ?cls . ?cls wdt:P279* ?routeRoot .
                BIND(?item AS ?grp) BIND(?item AS ?ed) BIND(false AS ?stage) }
              OPTIONAL { ?item wdt:P585 ?when }
            } GROUP BY ?grp ?rel ?stage ORDER BY DESC(?last) DESC(?n) LIMIT 30
            SPARQL;
        // The query service is the slowest of the four sources: 7 to 23 s for
        // Antwerp on 2026-09-07, and it sends nothing until it is done, so
        // the idle timeout has to cover the whole wait. Its own cap is 60 s.
        $data = $this->get('https://query.wikidata.org/sparql', ['query' => $query, 'format' => 'json'], 50, 55);

        /** @var array<string, array{qid: string, rels: list<string>, n: int, last: ?int}> $byRace */
        $byRace = [];
        /** @var list<array<string, array{value?: mixed}>> $rows */
        $rows = \is_array($data['results']['bindings'] ?? null) ? $data['results']['bindings'] : [];
        foreach ($rows as $row) {
            $race = $row['grp']['value'] ?? null;
            $rel = $row['rel']['value'] ?? null;
            if (!\is_string($race) || !\is_string($rel) || 1 !== preg_match('~/(Q\d+)$~', $race, $m)) {
                continue;
            }
            $raceQid = $m[1];
            $relName = match (true) {
                str_ends_with($rel, '/P1427') => 'start',
                str_ends_with($rel, '/P1444') => 'finish',
                default => 'via',
            };
            if ('true' === ($row['stage']['value'] ?? '')) {
                $relName = 'stage-'.$relName;
            }
            $n = (int) ($row['n']['value'] ?? 0);
            $lastRaw = $row['last']['value'] ?? null;
            $last = \is_string($lastRaw) && 1 === preg_match('~^(\d{4})~', $lastRaw, $y) ? (int) $y[1] : null;
            $entry = $byRace[$raceQid] ?? ['qid' => $raceQid, 'rels' => [], 'n' => 0, 'last' => null];
            if (!\in_array($relName, $entry['rels'], true)) {
                $entry['rels'][] = $relName;
            }
            $entry['n'] = max($entry['n'], $n);
            $entry['last'] = max($entry['last'] ?? 0, $last ?? 0) ?: null;
            $byRace[$raceQid] = $entry;
        }
        $out = array_values($byRace);
        usort($out, static fn (array $a, array $b): int => [$b['last'] ?? 0, $b['n']] <=> [$a['last'] ?? 0, $a['n']]);

        return \array_slice($out, 0, 8);
    }

    /** A population count older than this is history, not a fact about the town today. */
    public const int POPULATION_MAX_AGE_YEARS = 25;

    /**
     * Two facts for the town card: when the place was founded (P571) and the
     * newest population count (P1082) with the year it was taken. Either may
     * be missing; Antwerp itself has no inception on Wikidata (2026-09-08).
     * A count with no date, or older than POPULATION_MAX_AGE_YEARS, is not
     * shown: Zwaag's newest count on Wikidata is from 1971 (owner
     * 2026-09-08), and "Inhabitants 2,712 (1971)" reads as a fact about now.
     *
     * @return array{founded?: array{year: int, precision: int}, population?: array{n: int, year: ?int}}
     *
     * @throws CommonsUnavailable
     */
    public function townFacts(string $qid): array
    {
        if (1 !== preg_match('~^Q\d+$~', $qid)) {
            throw new \InvalidArgumentException('Not a Wikidata id: '.$qid);
        }
        $facts = [];

        $founded = self::bestClaim($this->claims($qid, 'P571'));
        $time = $founded['mainsnak']['datavalue']['value']['time'] ?? null;
        $precision = $founded['mainsnak']['datavalue']['value']['precision'] ?? null;
        if (\is_string($time) && 1 === preg_match('~^([+-])(\d{1,16})-~', $time, $m) && \is_int($precision) && $precision >= 7) {
            $year = (int) $m[2] * ('-' === $m[1] ? -1 : 1);
            if (0 !== $year) {
                $facts['founded'] = ['year' => $year, 'precision' => $precision];
            }
        }

        $best = null;
        foreach ($this->claims($qid, 'P1082') as $claim) {
            $amount = $claim['mainsnak']['datavalue']['value']['amount'] ?? null;
            if (!\is_string($amount) || !is_numeric($amount)) {
                continue;
            }
            $when = $claim['qualifiers']['P585'][0]['datavalue']['value']['time'] ?? null;
            $year = \is_string($when) && 1 === preg_match('~^\+(\d{4})~', $when, $y) ? (int) $y[1] : null;
            if (null === $year || $year < (int) date('Y') - self::POPULATION_MAX_AGE_YEARS) {
                continue;
            }
            // Newest by year; a preferred-rank claim wins a tie.
            $score = [$year, 'preferred' === ($claim['rank'] ?? '') ? 1 : 0];
            if (null === $best || $score > $best) {
                $best = $score;
                $facts['population'] = ['n' => (int) $amount, 'year' => $year];
            }
        }

        return $facts;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws CommonsUnavailable
     */
    private function claims(string $qid, string $property): array
    {
        $data = $this->get('https://www.wikidata.org/w/api.php', [
            'action' => 'wbgetclaims',
            'format' => 'json',
            'entity' => $qid,
            'property' => $property,
        ]);
        /** @var list<array<string, mixed>> $claims */
        $claims = \is_array($data['claims'][$property] ?? null) ? array_values($data['claims'][$property]) : [];

        return $claims;
    }

    /**
     * The claim Wikidata itself puts first: preferred rank, else the first normal one.
     *
     * @param list<array<string, mixed>> $claims
     *
     * @return array<string, mixed>|null
     */
    private static function bestClaim(array $claims): ?array
    {
        foreach ($claims as $claim) {
            if ('preferred' === ($claim['rank'] ?? '')) {
                return $claim;
            }
        }
        foreach ($claims as $claim) {
            if ('deprecated' !== ($claim['rank'] ?? '')) {
                return $claim;
            }
        }

        return null;
    }

    /** Cut at a sentence end where one falls in the last third, else at a word. */
    private static function clip(string $text): string
    {
        if (mb_strlen($text) <= self::EXTRACT_MAX) {
            return $text;
        }
        $head = mb_substr($text, 0, self::EXTRACT_MAX);
        $cut = max((int) mb_strrpos($head, '. '), (int) mb_strrpos($head, '! '), (int) mb_strrpos($head, '? '));
        if ($cut >= (int) (self::EXTRACT_MAX * 0.66)) {
            return mb_substr($head, 0, $cut + 1);
        }
        $space = (int) mb_strrpos($head, ' ');

        return rtrim(mb_substr($head, 0, $space > 0 ? $space : self::EXTRACT_MAX), ' ,;:').'…';
    }

    /**
     * The rendering itself.
     *
     * This lives here and not in the handler because it is a request to
     * Wikimedia, and Wikimedia's rate limits are per client rather than per
     * endpoint. The handler used to fetch the bytes itself with no User-Agent
     * at all, so the metadata call identified us and the download that followed
     * it did not: upload.wikimedia.org answered 429 and the photo never
     * appeared. Found on the first real end-to-end run, 2026-08-25.
     *
     * @throws CommonsUnavailable
     */
    public function fetchThumb(string $url, int $maxBytes): string
    {
        try {
            $response = $this->http->request('GET', $url, [
                'headers' => ['User-Agent' => $this->userAgent],
                'timeout' => 30,
                'max_duration' => 60,
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new CommonsUnavailable('http_'.$response->getStatusCode());
            }
            $bytes = $response->getContent();
        } catch (CommonsUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CommonsUnavailable($e->getMessage());
        }

        if (\strlen($bytes) > $maxBytes) {
            throw new CommonsUnavailable('too_large');
        }

        return $bytes;
    }

    /**
     * Words that label a headcount in an infobox, by Wikipedia language.
     *
     * Not one list: the row is `Inwoners` on nl, `Einwohner` on de, `人口` on
     * ja. A language with no entry falls back to the English words, which is
     * right because the English article is the second thing tried.
     */
    private const array POPULATION_LABEL = [
        'nl' => 'inwoner|bevolking',
        'de' => 'einwohner',
        'fr' => 'population|habitants',
        'es' => 'poblaci|habitantes',
        'it' => 'abitanti|popolazione',
        'ja' => '人口',
        'en' => 'population',
    ];

    /**
     * Labels whose number is not a headcount.
     *
     * Mostly the two other things an infobox counts by head: density, and
     * money per capita. de.wikipedia's Hamburg carries `Kaufkraft je
     * Einwohner: 28.931 EUR`, and matching it reports a city of 1.9 million as
     * a town of 29 thousand.
     */
    private const string NOT_POPULATION =
        'densit|dichtheid|dichte|densidad|密度|hoogte|höhe|altitud|elevation'
        .'|area|oppervlak|fläche|superficie|面積|inkomen|income|revenu|renta'
        .'|einkommen|所得|piramide|pyramid|prognose|forecast'
        .'|kaufkraft|\bbip\b|\bpib\b|\bgdp\b|\bbbp\b'
        .'|je einwohner|pro einwohner|per inwoner|par habitant|per capita'
        .'|por habitante|€|\$|£|¥';

    /** A row that answers a `Population (2021)` header with the whole place. */
    private const string TOTAL_LABEL = 'total|totaal|gesamt|insgesamt|総数|計|municipio|commune|stadt|city|urban';

    /**
     * The headcount in a Wikipedia article's infobox, or null.
     *
     * **Why Wikipedia at all, when Wikidata is structured and this is a
     * scrape.** Because Wikidata does not have the answer often enough. A
     * survey of 100 OpenStreetMap places carrying a `wikidata` tag, ten per
     * country across ten countries and stratified by size (2026-09-12), found
     * Wikidata could answer 66% of them and only **47% of villages**. The same
     * places answered 89% and 82% once Wikipedia was allowed to fill the gap.
     * GeoNames was measured too and rejected: it lifted villages to 55% only,
     * and disagreed with Wikipedia on a third of the places where both had a
     * number, because its snapshot is not refreshed per municipality.
     *
     * **The fallback, never the answer when Wikidata has one.** The structured
     * value is exact, dated and ranked; this one is read off a table anybody
     * may restyle. Keeping Wikidata first also keeps the two from being mixed,
     * which matters more than it sounds: the survey found Rwandan districts
     * and their namesake towns reported under one name, 319,141 against
     * 82,797.
     *
     * Section 0 only, which is where the infobox is and about 33 KB rather
     * than the whole article.
     *
     * @return array{n: int, year: ?int}|null
     *
     * @throws CommonsUnavailable
     */
    public function infoboxPopulation(string $lang, string $title): ?array
    {
        if (1 !== preg_match('~^[a-z]{2}$~', $lang)) {
            throw new \InvalidArgumentException('Not a Wikipedia language code: '.$lang);
        }
        $data = $this->get(sprintf('https://%s.wikipedia.org/w/api.php', $lang), [
            'action' => 'parse',
            'format' => 'json',
            'formatversion' => '2',
            'page' => $title,
            'prop' => 'text',
            'section' => '0',
            'redirects' => '1',
        ]);
        $html = $data['parse']['text'] ?? null;

        return \is_string($html) ? self::readPopulation($lang, $html) : null;
    }

    /**
     * @return array{n: int, year: ?int}|null
     */
    private static function readPopulation(string $lang, string $html): ?array
    {
        $labels = self::POPULATION_LABEL[$lang] ?? self::POPULATION_LABEL['en'];
        preg_match_all('~<tr\b.*?</tr>~si', $html, $rows);

        $armedYear = null;
        $countdown = 0;
        foreach ($rows[0] as $row) {
            preg_match_all('~<t[hd]\b[^>]*>(.*?)</t[hd]>~si', $row, $found);
            $cells = array_map(self::cellText(...), $found[1]);
            if ([] === $cells) {
                continue;
            }
            $label = $cells[0];

            // Whichever word comes FIRST owns the row. A label can carry both:
            // nl.wikipedia stacks "Inwoners - Mannen - Vrouwen -
            // Bevolkingsdichtheid" over one cell and the count is still the
            // first thing in it, while "Kaufkraft je Einwohner" is money and
            // never a headcount.
            $isPop = preg_match('~'.$labels.'~iu', $label, $m, \PREG_OFFSET_CAPTURE);
            $isNot = preg_match('~'.self::NOT_POPULATION.'~iu', $label, $n, \PREG_OFFSET_CAPTURE);
            if (1 === $isNot && (1 !== $isPop || $n[0][1] < $m[0][1])) {
                continue;
            }

            if (1 === $isPop) {
                $year = self::yearIn($label);
                // From a VALUE cell only. A label never holds the count, and
                // reading one is how a year becomes a population.
                foreach (\array_slice($cells, 1) as $cell) {
                    $count = self::countIn($cell);
                    if (null !== $count) {
                        return ['n' => $count, 'year' => $year ?? self::yearIn($cell)];
                    }
                }
                // No value here: the `Population (2021)` header shape, whose
                // count arrives on one of the next rows.
                $armedYear = $year;
                $countdown = 4;

                continue;
            }

            if ($countdown > 0) {
                --$countdown;
                if (1 !== preg_match('~'.self::TOTAL_LABEL.'~iu', $label)) {
                    continue;
                }
                foreach (\array_slice($cells, 1) ?: $cells as $cell) {
                    $count = self::countIn($cell);
                    if (null !== $count) {
                        return ['n' => $count, 'year' => $armedYear ?? self::yearIn($cell)];
                    }
                }
            }
        }

        return null;
    }

    /** One table cell as plain text, footnote markers dropped. */
    private static function cellText(string $html): string
    {
        $html = preg_replace('~<(sup|style)\b.*?</\1>~si', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    }

    /**
     * A headcount in this cell, or null.
     *
     * Thousands separators differ by language, so 1.234, 1 234 and 1,234 are
     * all one number. A bare four-digit year is refused: nl.wikipedia labels a
     * section "Inwoners van jaar tot jaar op 1 januari 1992 tot heden", and
     * 1992 passes every other test. A village of exactly 1992 people written
     * without a separator loses here, which is the cheaper mistake.
     */
    private static function countIn(string $cell): ?int
    {
        if (1 !== preg_match('~\d{1,3}(?:[.,\x{00A0}\x{202F} ]\d{3})+|\b\d{2,}\b~u', $cell, $m)) {
            return null;
        }
        $digits = preg_replace('~\D~', '', $m[0]) ?? '';
        if ('' === $digits) {
            return null;
        }
        $n = (int) $digits;
        if ($n < 10 || $n > 40_000_000) {
            return null;
        }
        if ($n >= 1700 && $n <= 2035 && 1 === preg_match('~^\s*\d{4}\s*$~', $cell)) {
            return null;
        }

        return $n;
    }

    private static function yearIn(string $text): ?int
    {
        return 1 === preg_match('~\b(19\d\d|20[0-2]\d)\b~', $text, $m) ? (int) $m[1] : null;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     *
     * @throws CommonsUnavailable
     */
    private function get(string $url, array $query, int $timeout = 15, int $maxDuration = 30): array
    {
        try {
            $response = $this->http->request('GET', $url, [
                'query' => $query,
                'headers' => ['User-Agent' => $this->userAgent, 'Accept' => 'application/json'],
                'timeout' => $timeout,
                'max_duration' => $maxDuration,
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new CommonsUnavailable('http_'.$response->getStatusCode());
            }

            return $response->toArray();
        } catch (CommonsUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CommonsUnavailable($e->getMessage());
        }
    }

    /**
     * @param array<string, string> $form
     *
     * @return array<string, mixed>
     *
     * @throws CommonsUnavailable
     */
    private function post(string $url, array $form): array
    {
        try {
            $response = $this->http->request('POST', $url, [
                'body' => $form,
                'headers' => ['User-Agent' => $this->userAgent, 'Accept' => 'application/json'],
                'timeout' => 15,
                'max_duration' => 30,
            ]);
            if (200 !== $response->getStatusCode()) {
                throw new CommonsUnavailable('http_'.$response->getStatusCode());
            }

            return $response->toArray();
        } catch (CommonsUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CommonsUnavailable($e->getMessage());
        }
    }

    /** `<a ...>Jean-Pol GRANDMONT</a>` becomes `Jean-Pol GRANDMONT`. */
    private static function plainCredit(string $html): string
    {
        $text = trim(html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $text = trim(preg_replace('~\s+~u', ' ', $text) ?? $text);

        return '' === $text ? 'Wikimedia Commons' : mb_substr($text, 0, 255);
    }

    /** The Commons username behind the Artist link, for the credit URL. */
    private static function commonsUser(string $html): ?string
    {
        if (!preg_match('~/wiki/User:([^"\'#?<>]+)~', $html, $m)) {
            return null;
        }
        $user = trim(rawurldecode($m[1]));

        return '' === $user ? null : mb_substr($user, 0, 255);
    }
}
