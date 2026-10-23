<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\Entity\Submission;
use App\Catalog\OperationalRegions;
use App\Catalog\RegionFacts;
use App\Catalog\RegionLead;
use App\Catalog\RegionStory;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Moderation\AlreadyDecidedException;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\ModerationService;
use App\Moderation\OutOfScopeException;
use App\Moderation\PlaceTextWriter;
use App\Routing\Languages;
use App\Town\TownPlaceRepository;
use App\Town\TownSummaryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Anyone signed in proposes a town card's or a region page's about text; a
 * curator of that region approves it (owner 2026-09-30).
 *
 * A proposal is an ordinary submission of type Text in the one queue, filed in
 * the region the town lies in (or the region itself), so the curators whose
 * area it is see it and nobody else does. The same decision, thread, withdraw
 * and history as every other submission. A curator's own proposal inside
 * their area applies at once, the rule every contribution follows
 * (moderation-and-contribution.md §1.6); outside it, it queues like a rider's.
 *
 * A rider who proposes again for the same text and language while the first
 * is still open amends it rather than filing a second. A curator of the
 * submission's area may correct a waiting proposal before deciding it; it
 * stays the rider's proposal ({@see self::correct()}).
 *
 * Where the text's language has a Wikipedia article to credit, approving it
 * takes the curator's explicit decision on that credit (owner 2026-10-01):
 * keep it, the text is based on the article, or drop it, the text is written
 * fresh. The writer's own choice ("Based on the default Wikipedia text"
 * or "I wrote my own text", required where there is an article) is
 * their claim only; the decision is recorded on the payload
 * ({@see self::recordCredit()}) in the approval's transaction, and the
 * generic decision refuses a text that has none
 * ({@see PlaceTextWriter::creditUndecided()}).
 *
 * A region's "History, nature and culture" text (target `story`) runs the
 * same way: one proposal per language, written fresh or translated, its
 * sources linked in the text, and no Wikipedia question.
 *
 * A region's figures (target `facts`: area, population with its year, ride
 * character) are sent on the geography form, beside its text or alone, as
 * a proposal of their own: no language of their own, no Wikipedia question,
 * and decided apart from the text (§3.1g).
 *
 * @see docs/specs/moderation-and-contribution.md §3.1b, §3.1c and §3.1g
 *
 * @api
 */
final readonly class PlaceTextProposals
{
    /** The note to the writer on approval: the limit of the map's decision form. */
    public const int REPLY_MAX = 2000;

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private TownSummaryRepository $towns,
        private TownPlaceRepository $places,
        private RateLimiterFactoryInterface $contributionSubmitLimiter,
        private RateLimiterFactoryInterface $contributionSubmitCuratorLimiter,
        private ModerationService $moderation,
        private RoleHierarchyInterface $roleHierarchy,
        private ModerationScopeProvider $scopes,
        private Languages $languages,
        private StoryLinkCheck $links,
        private TextLineage $lineage,
        private ClockInterface $clock,
    ) {
    }

    /** A town card's current text in one language, or '' when it has none. */
    public function currentTownText(string $osmRef, string $lang): string
    {
        $row = $this->towns->find($osmRef, $lang);

        return null !== $row && $row['answered'] ? (string) $row['extract'] : '';
    }

    /**
     * A town card's current text in one language and whether it is based on
     * the Wikipedia article: the fetched article is, a local text as it was
     * approved.
     *
     * @return array{text: string, derived: bool}
     */
    public function currentTown(string $osmRef, string $lang): array
    {
        $row = $this->towns->find($osmRef, $lang);
        if (null === $row || !$row['answered']) {
            return ['text' => '', 'derived' => false];
        }
        $text = (string) $row['extract'];

        return ['text' => $text, 'derived' => '' !== $text && $row['derived'] && null !== $row['page_url']];
    }

    /** Whether a town card's text in this language has a Wikipedia article to credit. */
    public function townHasArticle(string $osmRef, string $lang): bool
    {
        return $this->towns->hasArticle($osmRef, $lang);
    }

    /**
     * The town's name as the queue shows it: what the card called it, else
     * the title any language row holds, else the ref.
     */
    public function townTitle(string $osmRef, ?string $name): string
    {
        $name = trim((string) $name);
        if ('' !== $name) {
            return mb_substr($name, 0, 200);
        }
        $title = $this->db->fetchOne(
            'SELECT title FROM town_summary WHERE osm_ref = :r AND title IS NOT NULL ORDER BY (lang = \'en\') DESC, lang LIMIT 1',
            ['r' => $osmRef],
        );

        return \is_string($title) && '' !== $title ? mb_substr($title, 0, 200) : $osmRef;
    }

    /**
     * An operational region by slug, as the region page shows it.
     *
     * @return array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float}|null
     */
    public function region(string $slug): ?array
    {
        return $this->regionWhere('r.slug = :key', $slug);
    }

    /**
     * An operational region by id, as a region text's payload names it.
     *
     * @return array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float}|null
     */
    public function regionById(int $id): ?array
    {
        return $this->regionWhere('r.id = :key', $id);
    }

    /**
     * What readers see now for the text a proposal names, and whether that
     * language has a Wikipedia article to adapt and credit.
     *
     * @param array{target: string, ref: string, lang: string, text: string, derived: bool} $proposal
     *
     * @return array{text: string, derived: bool, canDerive: bool}
     *
     * @throws PlaceTextRefused when the region it names is gone
     */
    public function liveText(array $proposal): array
    {
        if (PlaceText::TOWN === $proposal['target']) {
            return $this->currentTown($proposal['ref'], $proposal['lang']) + ['canDerive' => $this->towns->hasArticle($proposal['ref'], $proposal['lang'])];
        }
        $region = $this->regionById((int) $proposal['ref']);
        if (null === $region) {
            throw new PlaceTextRefused('place_text.error.unknown');
        }
        if (PlaceText::STORY === $proposal['target']) {
            return ['text' => $this->currentStory($region, $proposal['lang']), 'derived' => false, 'canDerive' => false];
        }

        return $this->currentRegionText($region, $proposal['lang']) + ['canDerive' => RegionLead::hasSource($region['wiki'], $proposal['lang'])];
    }

    /**
     * A region's history text in exactly one locale, or '' when that locale
     * has none.
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     */
    public function currentStory(array $region, string $locale): string
    {
        return RegionStory::entry($region['story'], $locale)['text'] ?? '';
    }

    /**
     * A region's current lead in exactly one locale: the local one, else the
     * article in that language, else ''.
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     *
     * @return array{text: string, derived: bool}
     */
    public function currentRegionText(array $region, string $locale): array
    {
        $own = RegionLead::override($region['curated'], $locale);
        if (null !== $own) {
            return ['text' => $own['text'], 'derived' => $own['derived']];
        }
        $entry = $region['wiki'][$locale] ?? null;
        $extract = \is_array($entry) && \is_string($entry['extract'] ?? null) ? trim($entry['extract']) : '';

        // A reader starting from the article is adapting it.
        return ['text' => $extract, 'derived' => '' !== $extract];
    }

    /**
     * `$derived` is the writer's answer to where the text comes from: true
     * "Based on the default Wikipedia text", false "I wrote my own text",
     * null no answer. Where the language has an article the answer is
     * required; where it has none there is no question and the text is the
     * writer's own.
     *
     * @return array{submission: Submission, applied: bool}
     *
     * @throws PlaceTextRefused on text the form must send back
     * @throws LimitReached     past the contribution rate limit
     */
    public function proposeTown(User $by, string $osmRef, string $lang, string $text, string $note, ?string $name, ?bool $derived, ?string $basedOn = null): array
    {
        if (1 !== preg_match('~^(node|way|relation)/\d{1,16}$~', $osmRef)) {
            throw new PlaceTextRefused('place_text.error.unknown');
        }
        $this->assertServed($lang);
        [$lang, $text, $note] = $this->checked($lang, $text, $note);
        // What it translates, checked before it costs a send (§3.1e).
        $based = $this->lineage->basedOn(PlaceText::TOWN, $osmRef, $lang, (string) $basedOn);
        $derived = self::sourceAnswered($this->towns->hasArticle($osmRef, $lang), $derived);

        $where = $this->places->locate($osmRef);
        if (null === $where) {
            throw new PlaceTextRefused('place_text.error.no_location');
        }

        // The words are the proposal; the adaptation claim alone changes
        // nothing a reader sees until a curator decides the credit.
        $was = $this->currentTownText($osmRef, $lang);
        if ($was === $text) {
            throw new PlaceTextRefused('place_text.error.unchanged');
        }
        $this->consumeRateLimit($by);

        $point = json_encode(['type' => 'Point', 'coordinates' => [$where['lng'], $where['lat']]], \JSON_THROW_ON_ERROR);

        return $this->file($by, [
            'target' => PlaceText::TOWN,
            'ref' => $osmRef,
            'lang' => $lang,
            'text' => $text,
            'derived' => $derived,
            'details' => ['note' => $note],
        ] + self::basedOnPayload($based), [PlaceText::changeKey($lang) => self::pair($was, $text)], $this->townTitle($osmRef, $name), $point, $where['countryCode'], $where['regionId']);
    }

    /**
     * `$derived` is the writer's answer to where the text comes from, as for
     * a town ({@see self::proposeTown()}).
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     *
     * @return array{submission: Submission, applied: bool}
     *
     * @throws PlaceTextRefused on text the form must send back
     * @throws LimitReached     past the contribution rate limit
     */
    public function proposeRegion(User $by, array $region, string $lang, string $text, string $note, ?bool $derived, ?string $basedOn = null): array
    {
        ['lang' => $lang, 'text' => $text, 'note' => $note, 'based' => $based, 'derived' => $derived, 'was' => $was] = $this->regionChecked($region, $lang, $text, $note, $derived, $basedOn);
        $this->consumeRateLimit($by);

        $point = $this->db->fetchOne('SELECT ST_AsGeoJSON(ST_PointOnSurface(geom)) FROM region WHERE id = :id', ['id' => $region['id']]);
        if (!\is_string($point)) {
            throw new PlaceTextRefused('place_text.error.unknown');
        }

        return $this->file($by, [
            'target' => PlaceText::REGION,
            'ref' => (string) $region['id'],
            'slug' => $region['slug'],
            'lang' => $lang,
            'text' => $text,
            'derived' => $derived,
            'details' => ['note' => $note],
        ] + self::basedOnPayload($based), [PlaceText::changeKey($lang) => self::pair($was, $text)], mb_substr($region['name'], 0, 200), $point, $region['countryCode'], $region['id']);
    }

    /**
     * Whether a geography text would be refused, checked exactly as
     * {@see self::proposeRegion()} checks it, without filing it: the region
     * page's one send checks every part before it files any (§3.1g).
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     *
     * @throws PlaceTextRefused
     */
    public function checkRegion(array $region, string $lang, string $text, string $note, ?bool $derived, ?string $basedOn = null): void
    {
        $this->regionChecked($region, $lang, $text, $note, $derived, $basedOn);
    }

    /**
     * Whether a history text would be refused, checked exactly as
     * {@see self::proposeStory()} checks it, links included, without filing
     * it.
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     *
     * @throws PlaceTextRefused
     */
    public function checkStory(array $region, string $lang, string $text, string $note, bool $linksConfirmed = false, ?string $basedOn = null): void
    {
        $this->storyChecked($region, $lang, $text, $note, $linksConfirmed, $basedOn);
    }

    /**
     * Whether a history text box holds what readers see now in that
     * language, once folded as it would be stored. The region page's box
     * starts from that text, so a box left as it was, or emptied, sends no
     * history text.
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     */
    public function storyUnchanged(array $region, string $lang, string $text): bool
    {
        $text = RegionStory::normaliseText($text);

        return '' === $text || $text === $this->currentStory($region, $lang);
    }

    /**
     * A geography text as a send checks it: the words and note
     * ({@see self::checked()}), what it translates (§3.1e), the writer's
     * answer on the Wikipedia article, and that it changes what readers see.
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     *
     * @return array{lang: string, text: string, note: string, based: array{lang: string, at: ?string, sub: ?int}|null, derived: bool, was: string}
     *
     * @throws PlaceTextRefused
     */
    private function regionChecked(array $region, string $lang, string $text, string $note, ?bool $derived, ?string $basedOn): array
    {
        $this->assertServed($lang);
        [$lang, $text, $note] = $this->checked($lang, $text, $note);
        // What it translates, checked before it costs a send (§3.1e).
        $based = $this->lineage->basedOn(PlaceText::REGION, (string) $region['id'], $lang, (string) $basedOn);
        $derived = self::sourceAnswered(RegionLead::hasSource($region['wiki'], $lang), $derived);
        $current = $this->currentRegionText($region, $lang);
        if ($current['text'] === $text && $current['derived'] === $derived) {
            throw new PlaceTextRefused('place_text.error.unchanged');
        }

        return ['lang' => $lang, 'text' => $text, 'note' => $note, 'based' => $based, 'derived' => $derived, 'was' => $current['text']];
    }

    /**
     * A history text as a send checks it: the words and note, what it
     * translates, that it changes what readers see, and its links.
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     *
     * @return array{lang: string, text: string, note: string, based: array{lang: string, at: ?string, sub: ?int}|null, was: string, problems: array<string, string>, earlier: list<string>}
     *
     * @throws PlaceTextRefused
     */
    private function storyChecked(array $region, string $lang, string $text, string $note, bool $linksConfirmed, ?string $basedOn): array
    {
        $this->assertServed($lang);
        [$lang, $text, $note] = $this->checked($lang, $text, $note, PlaceText::STORY);
        // What it translates, checked before it costs a send (§3.1e).
        $based = $this->lineage->basedOn(PlaceText::STORY, (string) $region['id'], $lang, (string) $basedOn);
        $current = $this->currentStory($region, $lang);
        if ($current === $text) {
            throw new PlaceTextRefused('place_text.error.unchanged');
        }
        [$problems, $earlier] = $this->linkProblems($text, $linksConfirmed);

        return ['lang' => $lang, 'text' => $text, 'note' => $note, 'based' => $based, 'was' => $current, 'problems' => $problems, 'earlier' => $earlier];
    }

    /**
     * A region's "History, nature and culture" text in one language: a new
     * text, an edit, or a translation of another language's text. There is
     * no Wikipedia question: the text is the writer's own, and it links the
     * sources of its facts in place.
     *
     * A link that is not plainly fine needs the writer's confirmation that
     * they opened it and it is right (`$linksConfirmed`, the form's tick),
     * unless a curator confirmed it before (StoryLinkCheck::open()); it is
     * kept on the payload (`_links.writer`).
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     *
     * @return array{submission: Submission, applied: bool}
     *
     * @throws PlaceTextRefused on text the form must send back
     * @throws LimitReached     past the contribution rate limit
     */
    public function proposeStory(User $by, array $region, string $lang, string $text, string $note, bool $linksConfirmed = false, ?string $basedOn = null): array
    {
        ['lang' => $lang, 'text' => $text, 'note' => $note, 'based' => $based, 'was' => $current, 'problems' => $problems, 'earlier' => $earlier] = $this->storyChecked($region, $lang, $text, $note, $linksConfirmed, $basedOn);
        $this->consumeRateLimit($by);

        $point = $this->db->fetchOne('SELECT ST_AsGeoJSON(ST_PointOnSurface(geom)) FROM region WHERE id = :id', ['id' => $region['id']]);
        if (!\is_string($point)) {
            throw new PlaceTextRefused('place_text.error.unknown');
        }

        return $this->file($by, [
            'target' => PlaceText::STORY,
            'ref' => (string) $region['id'],
            'slug' => $region['slug'],
            'lang' => $lang,
            'text' => $text,
            'derived' => false,
            'details' => ['note' => $note],
        ] + ([] === $problems ? [] : ['_links' => ['writer' => StoryLinkCheck::confirmation($by, $problems, $earlier)]])
          + self::basedOnPayload($based), [PlaceText::changeKey($lang, PlaceText::STORY) => self::pair($current, $text)], mb_substr($region['name'], 0, 200), $point, $region['countryCode'], $region['id']);
    }

    /**
     * A region's figures as the geography form starts them, and the area
     * measured from its boundary, which the page shows while nobody stated
     * one (§3.1g).
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     *
     * @return array{area: ?int, population: ?int, year: ?int, ride: ?string, populationQid: ?string, measuredKm2: ?int}
     */
    public function currentFacts(array $region): array
    {
        return RegionFacts::current($region['facts'], $region['harvest']) + ['measuredKm2' => self::measured($region)];
    }

    /**
     * What the figures a form sends change, checked (§3.1g): one was/now
     * pair per figure that differs from what stands. A field left as it is,
     * or emptied, changes nothing: a figure is changed, never removed. An
     * area equal to the measured one changes nothing while none is stated.
     * The population comes with the year it was counted.
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     * @param array<array-key, mixed>                                                                                                                                                                                                                   $raw    the form's `facts[area]`, `facts[population]`, `facts[year]` and `facts[ride]`
     *
     * @return array<string, array{was: mixed, now: mixed}> by figure, in RegionFacts::FIELDS order
     *
     * @throws PlaceTextRefused on a figure the form must send back
     */
    public function factChanges(array $region, array $raw): array
    {
        $current = RegionFacts::current($region['facts'], $region['harvest']);
        $changes = [];

        $area = self::wholeNumber($raw['area'] ?? null, 1, RegionFacts::AREA_MAX, 'place_text.error.facts_area');
        if (null !== $area && $area !== $current['area'] && !(null === $current['area'] && $area === self::measured($region))) {
            $changes[RegionFacts::AREA] = ['was' => $current['area'], 'now' => $area];
        }

        $population = self::wholeNumber($raw['population'] ?? null, 0, RegionFacts::POPULATION_MAX, 'place_text.error.facts_population');
        $thisYear = (int) $this->clock->now()->format('Y');
        $year = self::wholeNumber($raw['year'] ?? null, RegionFacts::YEAR_MIN, $thisYear, 'place_text.error.facts_year', ['%min%' => (string) RegionFacts::YEAR_MIN, '%max%' => (string) $thisYear]);
        if (null !== $population || null !== $year) {
            if (null === $population || null === $year) {
                throw new PlaceTextRefused('place_text.error.facts_pair');
            }
            if ($population !== $current['population'] || $year !== $current['year']) {
                $changes[RegionFacts::POPULATION] = ['was' => RegionFacts::valueOf($current, RegionFacts::POPULATION), 'now' => ['value' => $population, 'year' => $year]];
            }
        }

        $ride = $raw['ride'] ?? null;
        $ride = \is_string($ride) ? trim($ride) : null;
        if (null !== $ride && '' !== $ride) {
            if (!\in_array($ride, RegionFacts::RIDES, true)) {
                throw new PlaceTextRefused('place_text.error.facts_ride');
            }
            if ($ride !== $current['ride']) {
                $changes[RegionFacts::RIDE] = ['was' => $current['ride'], 'now' => $ride];
            }
        }

        return $changes;
    }

    /**
     * A region's figures (§3.1g): the changes {@see self::factChanges()}
     * found, filed as one proposal in the region's queue, apart from any
     * text sent with them. `$lang` is the language the form was in, which
     * the note is written in. A rider's second send while their first is
     * open adds to it: a figure the second does not name stays as the first
     * set it, unless it now stands as the first asked.
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     * @param array<string, array{was: mixed, now: mixed}>                                                                                                                                                                                              $changes
     *
     * @return array{submission: Submission, applied: bool}
     *
     * @throws PlaceTextRefused on a note or a send the form must send back
     * @throws LimitReached     past the contribution rate limit
     */
    public function proposeFacts(User $by, array $region, string $lang, array $changes, string $note): array
    {
        $this->assertServed($lang);
        $note = trim($note);
        if (mb_strlen($note) > PlaceText::NOTE_MAX) {
            throw new PlaceTextRefused('place_text.error.note_too_long');
        }
        $payload = ['target' => PlaceText::FACTS, 'ref' => (string) $region['id']];
        $open = $this->openFor($by, $payload);
        $earlier = null !== $open ? (PlaceText::factsOf($open->getPayload())['facts'] ?? []) : [];
        $current = RegionFacts::current($region['facts'], $region['harvest']);
        $facts = [];
        $pairs = [];
        foreach (RegionFacts::FIELDS as $field) {
            $was = RegionFacts::valueOf($current, $field);
            $now = isset($changes[$field]) ? $changes[$field]['now'] : ($earlier[$field] ?? null);
            if (null === $now || $now === $was) {
                continue;
            }
            $facts[$field] = $now;
            $pairs[PlaceText::factKey($field)] = ['was' => $was, 'now' => $now];
        }
        if ([] === $facts) {
            throw new PlaceTextRefused('region_edit.error.nothing');
        }
        $this->consumeRateLimit($by);

        $point = $this->db->fetchOne('SELECT ST_AsGeoJSON(ST_PointOnSurface(geom)) FROM region WHERE id = :id', ['id' => $region['id']]);
        if (!\is_string($point)) {
            throw new PlaceTextRefused('place_text.error.unknown');
        }

        return $this->file($by, $payload + [
            'slug' => $region['slug'],
            'lang' => $lang,
            'facts' => $facts,
            'details' => ['note' => $note],
        ], $pairs, mb_substr($region['name'], 0, 200), $point, $region['countryCode'], $region['id']);
    }

    /**
     * A curator of the submission's area corrects a waiting proposal (a typo,
     * a wrong word) before deciding it (owner 2026-10-01).
     *
     * It stays the rider's proposal: same row, writer, status and thread.
     * `payload.text` and the `now` side of `changes` carry the corrected words
     * (`was` stays what readers saw when the rider sent it), and
     * `payload._corrected` records who corrected it, when, and what the rider
     * sent (their first words, however often it is corrected). The writer's
     * adaptation claim stays theirs: whether the Wikipedia credit stays is
     * the curator's decision at approval ({@see self::approve()}). The
     * rider's own later revision replaces the payload, and with it this
     * record: the words are theirs again.
     *
     * @throws PlaceTextRefused        on text the form must send back
     * @throws OutOfScopeException     outside the curator's areas
     * @throws AlreadyDecidedException once it is decided, withdrawn or held
     */
    public function correct(Submission $submission, User $curator, string $text): Submission
    {
        $this->assertCorrectable($submission, $curator);
        $proposal = PlaceText::fromPayload($submission->getPayload());
        if (null === $proposal) {
            throw new PlaceTextRefused('place_text.error.unknown');
        }
        [, $text] = $this->checked($proposal['lang'], $text, '', $proposal['target']);
        if ($text === $proposal['text']) {
            throw new PlaceTextRefused('place_text.error.correct_unchanged');
        }
        $live = $this->liveText($proposal);
        if ($text === $live['text'] && (PlaceText::REGION !== $proposal['target'] || $proposal['derived'] === $live['derived'])) {
            throw new PlaceTextRefused('place_text.error.unchanged');
        }

        return $this->em->wrapInTransaction(function () use ($submission, $curator, $proposal, $text): Submission {
            $this->em->lock($submission, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($submission);
            $this->assertCorrectable($submission, $curator);

            $payload = $submission->getPayload();
            $prior = \is_array($payload['_corrected'] ?? null) ? $payload['_corrected'] : [];
            $payload['_corrected'] = [
                'by' => (int) $curator->getId(),
                'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'from' => \is_string($prior['from'] ?? null) ? $prior['from'] : $proposal['text'],
            ];
            $payload['text'] = $text;
            $changes = $submission->getChanges();
            $key = PlaceText::changeKey($proposal['lang'], $proposal['target']);
            $was = \is_array($changes[$key] ?? null) ? ($changes[$key]['was'] ?? null) : null;
            $changes[$key] = ['was' => $was, 'now' => $text];

            $submission->setPayload($payload)->setChanges($changes);
            $this->em->flush();

            return $submission;
        });
    }

    /**
     * A curator of the area approves a waiting proposal from its correction
     * form (owner 2026-10-01): the text in the box, corrected or not, goes
     * live, and the note reaches the writer with the approval, as a note on
     * the map's Approve does.
     *
     * Where the language has a Wikipedia article to credit, `$credit` is the
     * curator's decision on it and is required: true keeps the credit (the
     * text is based on the article), false drops it (written fresh), null is
     * no decision and is sent back. Without an article there is no question,
     * and the text is credited to its writer alone.
     *
     * One transaction: a correction the approval refuses is not kept either.
     * The text in the box is checked as a correction is, so a changed text is
     * recorded in `_corrected` before it is applied.
     *
     * @throws PlaceTextRefused        on text, a note or a missing credit decision the form must send back
     * @throws OutOfScopeException     outside the curator's areas
     * @throws AlreadyDecidedException once it is decided, withdrawn or held
     */
    public function approve(Submission $submission, User $curator, string $text, ?bool $credit, string $note, bool $linksConfirmed = false): Submission
    {
        $this->assertCorrectable($submission, $curator);
        $proposal = PlaceText::fromPayload($submission->getPayload());
        if (null === $proposal) {
            throw new PlaceTextRefused('place_text.error.unknown');
        }
        [, $text] = $this->checked($proposal['lang'], $text, '', $proposal['target']);
        $note = trim($note);
        if (mb_strlen($note) > self::REPLY_MAX) {
            throw new PlaceTextRefused('moderate.error.note_too_long');
        }
        $canDerive = $this->liveText($proposal)['canDerive'];
        if ($canDerive && null === $credit) {
            throw new PlaceTextRefused('place_text.error.credit_required');
        }
        $derived = $canDerive && true === $credit;
        // A history text's links that are not plainly fine need the curator's own tick too.
        [$problems, $earlier] = PlaceText::STORY === $proposal['target'] ? $this->linkProblems($text, $linksConfirmed) : [[], []];

        return $this->em->wrapInTransaction(function () use ($submission, $curator, $proposal, $text, $derived, $note, $problems, $earlier): Submission {
            if ($text !== $proposal['text']) {
                $this->correct($submission, $curator, $text);
            }
            $this->recordCredit($submission, $curator, $derived);
            if (PlaceText::STORY === $proposal['target']) {
                $this->recordLinks($submission, $curator, $problems, $earlier);
            }

            return $this->moderation->decide((int) $submission->getId(), 'approve', $curator, '' === $note ? null : $note);
        });
    }

    /**
     * What the writer said about the Wikipedia article when they sent the
     * text: true "Based on the default Wikipedia text", false "I wrote
     * my own text", null when they were not asked (no article, or a town text
     * sent before the question existed).
     */
    public static function writerClaim(Submission $submission): ?bool
    {
        $payload = $submission->getPayload();
        $credit = $payload['_credit'] ?? null;
        if (\is_array($credit) && \array_key_exists('claim', $credit)) {
            return \is_bool($credit['claim']) ? $credit['claim'] : null;
        }

        return \is_bool($payload['derived'] ?? null) ? $payload['derived'] : null;
    }

    /**
     * Whether this curator may correct this submission now: inside their
     * areas, a Text proposal, still waiting and not held.
     *
     * @throws OutOfScopeException
     * @throws AlreadyDecidedException
     */
    public function assertCorrectable(Submission $submission, User $curator): void
    {
        if (!$this->scopes->allowsRegion($this->scopes->scopeFor($curator), $submission->getRegionId())) {
            throw new OutOfScopeException('Submission outside the curator\'s assigned areas.');
        }
        if (SubmissionType::Text !== $submission->getType()
            || !\in_array($submission->getStatus(), [SubmissionStatus::Pending, SubmissionStatus::NeedsInfo], true)
            || null !== $submission->getEscalatedAt()) {
            throw new AlreadyDecidedException(sprintf('Submission %d cannot be corrected now', (int) $submission->getId()));
        }
    }

    /**
     * @return array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float}|null
     */
    private function regionWhere(string $predicate, int|string $key): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT r.id, r.slug, r.name, r.country_code, r.context, r.context_curated, r.story, r.facts, r.facts_harvest, r.area_km2 FROM region r
              WHERE '.$predicate.' AND r.geom IS NOT NULL AND r.country_code <> \'\' AND '.OperationalRegions::predicate('r'),
            ['key' => $key],
        );
        if (false === $row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'name' => (string) $row['name'],
            'countryCode' => (string) $row['country_code'],
            'wiki' => PlaceText::decode($row['context']),
            'curated' => PlaceText::decode($row['context_curated']),
            'story' => PlaceText::decode($row['story']),
            'facts' => PlaceText::decode($row['facts']),
            'harvest' => PlaceText::decode($row['facts_harvest']),
            'areaKm2' => null === $row['area_km2'] ? null : (float) $row['area_km2'],
        ];
    }

    /**
     * The area measured from the region's boundary, in whole km², or null.
     *
     * @param array{id: int, slug: string, name: string, countryCode: string, wiki: ?array<string, mixed>, curated: ?array<string, mixed>, story: ?array<string, mixed>, facts: ?array<string, mixed>, harvest: ?array<string, mixed>, areaKm2: ?float} $region
     */
    private static function measured(array $region): ?int
    {
        return null === $region['areaKm2'] ? null : (int) round($region['areaKm2']);
    }

    /**
     * A whole number from a form field, or null for an empty one. Digits,
     * or digits grouped by threes with spaces, commas, dots or apostrophes
     * ("494,000", "494.000", "494 000"); anything else, or a number outside
     * the range, is sent back with `$error`.
     *
     * @param array<string, string> $params
     *
     * @throws PlaceTextRefused
     */
    private static function wholeNumber(mixed $raw, int $min, int $max, string $error, array $params = []): ?int
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_string($raw)) {
            throw new PlaceTextRefused($error, $params);
        }
        $raw = trim($raw);
        if ('' === $raw) {
            return null;
        }
        if (1 === preg_match('~^\d{1,3}(?:[ ,.\'\x{00A0}\x{202F}]\d{3})+$~u', $raw)) {
            $raw = preg_replace('~\D~u', '', $raw) ?? '';
        }
        if (1 !== preg_match('~^\d{1,10}$~', $raw) || (int) $raw < $min || (int) $raw > $max) {
            throw new PlaceTextRefused($error, $params);
        }

        return (int) $raw;
    }

    /**
     * The open Text submission this rider already has for the same text and
     * language, if any. A region's figures hold in every language, so their
     * open proposal is the same whatever language the form was in.
     *
     * @param array<string, mixed> $payload
     */
    private function openFor(User $by, array $payload): ?Submission
    {
        $facts = PlaceText::FACTS === $payload['target'];
        $id = $this->db->fetchOne(
            "SELECT id FROM submission
              WHERE user_id = :u AND type = :t AND status IN ('pending', 'needs_info') AND escalated_at IS NULL
                AND payload->>'target' = :target AND payload->>'ref' = :ref"
            .($facts ? '' : " AND payload->>'lang' = :lang")
            .' ORDER BY id DESC LIMIT 1',
            [
                'u' => (int) $by->getId(),
                't' => SubmissionType::Text->value,
                'target' => $payload['target'],
                'ref' => $payload['ref'],
            ] + ($facts ? [] : ['lang' => $payload['lang']]),
        );

        return false === $id ? null : $this->em->find(Submission::class, (int) $id);
    }

    /**
     * What a text translates, on its payload (`_basedOn`, §3.1e), or nothing.
     *
     * @param array{lang: string, at: ?string, sub: ?int}|null $basedOn
     *
     * @return array<string, mixed>
     */
    private static function basedOnPayload(?array $basedOn): array
    {
        return null === $basedOn ? [] : ['_basedOn' => $basedOn];
    }

    /**
     * One was/now pair for `changes`; an empty `was` is null.
     *
     * @return array{was: ?string, now: string}
     */
    private static function pair(string $was, string $now): array
    {
        return ['was' => '' === $was ? null : $was, 'now' => $now];
    }

    /**
     * @param array<string, mixed>                         $payload
     * @param array<string, array{was: mixed, now: mixed}> $changes
     *
     * @return array{submission: Submission, applied: bool}
     */
    private function file(User $by, array $payload, array $changes, string $title, string $point, string $countryCode, ?int $regionId): array
    {
        $submission = $this->em->wrapInTransaction(function () use ($by, $payload, $changes, $title, $point, $countryCode, $regionId): Submission {
            $open = $this->openFor($by, $payload);
            $submission = $open ?? (new Submission())
                ->setType(SubmissionType::Text)
                ->setLetter('')
                ->setItemId(null)
                ->setUserId((int) $by->getId());
            $submission
                ->setStatus(SubmissionStatus::Pending)
                ->setTitle($title)
                ->setGeom($point)
                ->setCountryCode($countryCode)
                ->setRegionId($regionId)
                ->setLang(\is_string($payload['lang'] ?? null) ? $payload['lang'] : null)
                ->setChanges($changes)
                ->setPayload($payload)
                ->setDecisionNote(null)
                ->setDecidedBy(null)
                ->setDecidedAt(null);
            if (null === $open) {
                $this->em->persist($submission);
            }
            $this->em->flush();

            return $submission;
        });

        return ['submission' => $submission, 'applied' => $this->applyIfCurator($submission, $by)];
    }

    /**
     * A curator's own proposal inside their area applies at once; outside it,
     * or for a town outside every region, it waits for another curator. Their
     * own answer on the form (adapted, or their own text) is the credit
     * decision, recorded as any approving curator's is.
     */
    private function applyIfCurator(Submission $submission, User $by): bool
    {
        if (!\in_array('ROLE_CURATOR', $this->roleHierarchy->getReachableRoleNames($by->getRoles()), true)
            || !$this->scopes->coversRegion($this->scopes->scopeFor($by), $submission->getRegionId())) {
            return false;
        }
        $derived = true === ($submission->getPayload()['derived'] ?? null);
        // A region's figures have no Wikipedia credit to decide.
        $credit = PlaceText::FACTS !== ($submission->getPayload()['target'] ?? null);
        // Their tick when they sent it is the approval's confirmation of the links.
        $writer = $submission->getPayload()['_links']['writer'] ?? [];
        $problems = \is_array($writer['problems'] ?? null) ? array_filter($writer['problems'], 'is_string') : [];
        $earlier = \is_array($writer['earlier'] ?? null) ? array_values(array_filter($writer['earlier'], 'is_string')) : [];
        try {
            $this->em->wrapInTransaction(function () use ($submission, $by, $derived, $credit, $problems, $earlier): void {
                if ($credit) {
                    $this->recordCredit($submission, $by, $derived);
                }
                if ([] !== $problems) {
                    $this->recordLinks($submission, $by, $problems, $earlier);
                }
                $this->moderation->decide((int) $submission->getId(), 'approve', $by, null);
            });
        } catch (OutOfScopeException) {
            return false;
        }

        return true;
    }

    /**
     * The approving curator's decision on the Wikipedia credit, on the
     * payload: `derived` becomes the decision, and `_credit` records who made
     * it, when, and what the writer had claimed. Called inside the approval's
     * transaction, so a refused approval keeps no decision.
     */
    private function recordCredit(Submission $submission, User $curator, bool $derived): void
    {
        $this->em->lock($submission, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($submission);
        $claim = self::writerClaim($submission);
        $payload = $submission->getPayload();
        $payload['derived'] = $derived;
        $payload['_credit'] = [
            'by' => (int) $curator->getId(),
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'claim' => $claim,
        ];
        $submission->setPayload($payload);
        $this->em->flush();
    }

    /**
     * The writer's adaptation claim. Where there is an article to adapt they
     * must say whether they adapted it or wrote their own text; where there
     * is none, the text is their own.
     *
     * @throws PlaceTextRefused when an article exists and they did not say
     */
    private static function sourceAnswered(bool $hasArticle, ?bool $derived): bool
    {
        if (!$hasArticle) {
            return false;
        }
        if (null === $derived) {
            throw new PlaceTextRefused('place_text.error.source_required');
        }

        return $derived;
    }

    /**
     * A new text is written only in a language this deployment serves. A
     * correction or a decision is not asked this: the proposal's language
     * is stored data, and one filed before its language was switched off
     * is still decided ({@see self::checked()} takes every built language).
     *
     * @throws PlaceTextRefused
     */
    private function assertServed(string $lang): void
    {
        if (!$this->languages->isServed($lang)) {
            throw new PlaceTextRefused('place_text.error.lang');
        }
    }

    /**
     * A history text keeps its paragraphs and has its own cap; the other
     * texts are one paragraph.
     *
     * @return array{0: string, 1: string, 2: string} language, text, note
     *
     * @throws PlaceTextRefused
     */
    private function checked(string $lang, string $text, string $note, string $target = PlaceText::TOWN): array
    {
        if (!\in_array($lang, PlaceText::LANGS, true)) {
            throw new PlaceTextRefused('place_text.error.lang');
        }
        $story = PlaceText::STORY === $target;
        $text = $story ? RegionStory::normaliseText($text) : PlaceText::normalise($text);
        if ('' === $text) {
            throw new PlaceTextRefused('place_text.error.empty');
        }
        $tooLong = $story
            ? RegionStory::readableLength($text) > RegionStory::MAX || mb_strlen($text) > RegionStory::RAW_MAX
            : mb_strlen($text) > PlaceText::MAX;
        if ($tooLong) {
            throw new PlaceTextRefused('place_text.error.too_long', ['%max%' => (string) ($story ? RegionStory::MAX : PlaceText::MAX)]);
        }
        if ($story && null !== RegionStory::genericAnchor($text)) {
            throw new PlaceTextRefused('place_text.error.generic_anchor');
        }
        // Only https addresses link (§3.1c).
        $insecure = $story ? RegionStory::insecureLinks($text) : [];
        if ([] !== $insecure) {
            throw new PlaceTextRefused('place_text.error.http_link', ['%urls%' => implode(', ', $insecure)]);
        }
        $note = trim($note);
        if (mb_strlen($note) > PlaceText::NOTE_MAX) {
            throw new PlaceTextRefused('place_text.error.note_too_long');
        }

        return [$lang, $text, $note];
    }

    /**
     * The links of a history text that are not plainly fine, and whether the
     * one sending or approving it confirmed them by hand
     * (moderation-and-contribution.md §3.1c). A link a curator already
     * confirmed with the same problem needs no new tick (StoryLinkCheck::open()).
     * Asked before any transaction, so a refusal leaves the request's entity
     * manager open.
     *
     * @return array{0: array<string, string>, 1: list<string>} url => problem (empty when every link checked out), and the addresses a curator had confirmed before
     *
     * @throws PlaceTextRefused when a link still to confirm has no tick
     */
    private function linkProblems(string $text, bool $confirmed): array
    {
        $problems = $this->links->problems($text);
        $open = $this->links->open($problems);
        if ([] !== $open && !$confirmed) {
            throw new PlaceTextRefused('place_text.error.links_unconfirmed', ['%urls%' => implode(', ', array_keys($open))]);
        }

        return [$problems, array_map('strval', array_keys(array_diff_key($problems, $open)))];
    }

    /**
     * The approving curator's confirmation of a history text's problem links,
     * on the payload (`_links.approved`), inside the approval's transaction;
     * none when every link checked out. `$earlier` names the ones a curator
     * had confirmed before.
     *
     * @param array<string, string> $problems
     * @param list<string>          $earlier
     */
    private function recordLinks(Submission $submission, User $curator, array $problems, array $earlier = []): void
    {
        $this->em->lock($submission, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($submission);
        $payload = $submission->getPayload();
        $links = \is_array($payload['_links'] ?? null) ? $payload['_links'] : [];
        unset($links['approved']);
        if ([] !== $problems) {
            $links['approved'] = StoryLinkCheck::confirmation($curator, $problems, $earlier);
        }
        $payload['_links'] = $links;
        if ([] === $links) {
            unset($payload['_links']);
        }
        $submission->setPayload($payload);
        $this->em->flush();
    }

    /** A curator or admin has their own, larger hour (security-architecture.md §7). */
    private function consumeRateLimit(User $by): void
    {
        $curator = \in_array('ROLE_CURATOR', $this->roleHierarchy->getReachableRoleNames($by->getRoles()), true);
        $rate = ($curator ? $this->contributionSubmitCuratorLimiter : $this->contributionSubmitLimiter)
            ->create('user-'.(string) $by->getId())->consume();
        if (!$rate->isAccepted()) {
            throw LimitReached::of($rate, 'hour');
        }
    }
}
