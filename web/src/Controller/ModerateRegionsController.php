<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CuratedReadiness;
use App\Catalog\OperationalRegions;
use App\Catalog\RegionLead;
use App\Entity\User;
use App\Moderation\ModerationScopeProvider;
use App\Routing\LocalePrefix;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Curator Regions desk — the only place `region.default_map_mode` is set.
 *
 * The choice is GATED, which is the whole point (owner decision "B"): a region
 * cannot be made to open in a mode it has nothing to show in, so the setting
 * can never be raised prematurely and recreate the empty-map trap the global
 * default was flipped to avoid. Both counts are shown next to their thresholds
 * whether or not the gate is open, so a curator can see how far off a region is.
 *
 * Three rungs since 2026-08-12, and each has its own bar:
 *  - **Everything** — always available; it is what every region starts in.
 *  - **Confirmed** — needs `map.confirmed_default_threshold` places somebody
 *    has vouched for. No breadth rule: it is not a selection, so a region whose
 *    confirmations are all water taps is still saying something true.
 *  - **Best of** — needs the breadth-and-depth readiness count, unchanged.
 *
 * A region can always be moved DOWN, whatever its counts: the gate exists to
 * stop premature promises, never to trap a region in a mode its content no
 * longer supports.
 *
 * Scoped like every other desk: a curator sees only their assigned regions;
 * a global curator or an admin sees all of them.
 *
 * @api Instantiated by Symfony's router.
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateRegionsController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'region-curated-default';
    private const string CSRF_ABOUT_ID = 'region-about-text';

    /**
     * A lead is a paragraph, not an essay. The Wikipedia extracts this sits
     * beside run 150-400 characters; the cap is generous against those and
     * still says "this is the lead, the map is the page".
     */
    private const int ABOUT_MAX = 1200;

    public function __construct(
        private readonly Connection $db,
        private readonly ModerationScopeProvider $scopeProvider,
        private readonly CuratedReadiness $readiness,
    ) {
    }

    #[Route('/moderate/regions', name: 'moderate_regions')]
    public function index(Request $request, TranslatorInterface $translator): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $rows = $this->visibleRegions($user);
        // Every country this curator can see, taken from the rows themselves so
        // the list can never offer a country with nothing behind it. Built
        // BEFORE the filter narrows them, or picking a country would leave the
        // select holding only that country (owner request, 2026-08-03).
        $countries = array_values(array_unique(array_map(
            static fn (array $r): string => (string) $r['countryCode'],
            $rows,
        )));
        sort($countries);
        $country = $request->query->getString('country');
        if ('' !== $country) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => (string) $r['countryCode'] === $country,
            ));
        }

        /* NOT PAGED (owner, 2026-08-14: "Pagination for Regions just remove it,
           it makes no sense"). It was paged by region, which broke the moment
           the desk grouped by country: 25 regions was four countries with
           fifteen hidden, and Japan's 47 prefectures spanned two pages. Paging
           by country fixed the splitting and was still the wrong shape — this
           is a settings desk, not a queue. A curator comes here to find one
           country and flip one region, and a pager turns "which regions open in
           Best of" into a question you have to visit three URLs to answer.

           It is bounded by construction, which is why dropping the pager does
           not reopen the unbounded-list problem the pagination sweep closed:
           the list is one collapsed row per ONBOARDED COUNTRY, a number that
           grows by deliberate human act a few times a year and stands at 19.
           The region cards inside are collapsed by default. Both readiness
           queries are batched (one GROUP BY over every id), so the whole desk
           costs two queries whatever its length. */
        $reports = $this->readiness->reportForRegions(array_map(static fn (array $r): int => $r['id'], $rows));
        // The middle rung's count, batched the same way and for the same reason.
        $confirmedCounts = $this->readiness->confirmedCounts(array_map(static fn (array $r): int => $r['id'], $rows));
        $confirmedThreshold = $this->readiness->confirmedThreshold();
        $threshold = $this->readiness->threshold();
        $minPerBlock = $this->readiness->minPerBlock();

        $regions = array_map(static function (array $r) use ($reports, $minPerBlock, $translator, $confirmedCounts, $confirmedThreshold): array {
            $rep = $reports[$r['id']];
            // Per-BLOCK, so a moderator can see WHICH kind of content the region
            // is short of, not just that a number is too low (owner request,
            // 2026-07-27). `met` marks a block that already carries its share of
            // the breadth requirement.
            $blocks = [];
            foreach (CuratedReadiness::BLOCKS as $letter => $labelKey) {
                $n = $rep['blocks'][$letter];
                $blocks[] = [
                    'letter' => $letter,
                    'label' => $translator->trans($labelKey),
                    'count' => $n,
                    'met' => $n >= $minPerBlock,
                ];
            }

            return [
                'id' => $r['id'],
                'slug' => $r['slug'],
                'countryCode' => $r['countryCode'],
                // Carried through for the continent → country → region grouping
                // the template renders; the rows arrive already in that order.
                'continent' => $r['continent'],
                'countryName' => $r['countryName'],
                'flag' => $r['flag'],
                'label' => $translator->trans('region.'.$r['slug'].'.label'),
                'defaultMode' => $r['defaultMode'],
                'confirmed' => $confirmedCounts[$r['id']] ?? 0,
                'confirmedThreshold' => $confirmedThreshold,
                'canConfirmed' => 'confirmed' === $r['defaultMode']
                    || ($confirmedCounts[$r['id']] ?? 0) >= $confirmedThreshold,
                'count' => $rep['total'],
                'blocks' => $blocks,
                'blocksMet' => $rep['blocksMet'],
                'shortTotal' => $rep['shortTotal'],
                'shortBlocks' => $rep['shortBlocks'],
                'ready' => $rep['ready'],
                // A region already flipped can always be flipped back, even if
                // its count later drops below the threshold — the gate exists to
                // stop premature ENABLING, never to trap a region in a mode its
                // content no longer supports.
                'canCurated' => 'curated' === $r['defaultMode'] || $rep['ready'],
            ];
        }, $rows);

        /* Continent → country → regions, as a real nested structure rather than
           change-detection in the template: each country is a collapsible
           <details>, and a <details> cannot be opened and closed across
           iterations of a flat loop without emitting unbalanced tags.

           Collapsed by default, because 19 countries of regions is several
           screens of vertical scroll before a curator finds anything (owner,
           2026-08-14). Two exceptions, both cases where a shut group would
           leave the page looking empty: a country the filter has narrowed to,
           and the only country on the page. The summary carries the counts, so
           a closed group still answers "is there anything to do here". */
        $groups = [];
        foreach ($regions as $r) {
            $groups[$r['continent']][$r['countryCode']]['name'] = $r['countryName'];
            $groups[$r['continent']][$r['countryCode']]['flag'] = $r['flag'];
            $groups[$r['continent']][$r['countryCode']]['regions'][] = $r;
        }
        $continents = [];
        foreach ($groups as $continentName => $byCountry) {
            $countryList = [];
            foreach ($byCountry as $cc => $g) {
                $ready = \count(array_filter($g['regions'], static fn (array $x): bool => (bool) $x['ready']));
                $countryList[] = [
                    'code' => $cc,
                    'name' => $g['name'],
                    'flag' => $g['flag'],
                    'regions' => $g['regions'],
                    'total' => \count($g['regions']),
                    'ready' => $ready,
                    'open' => '' !== $country || 1 === \count($byCountry) && 1 === \count($groups),
                ];
            }
            $continents[] = ['name' => $continentName, 'countries' => $countryList];
        }

        return $this->render('moderate_regions/index.html.twig', [
            'page_title' => 'moderate_regions.title',
            'page_description' => 'moderate_regions.lead',
            'nav_active' => 'moderate',
            'regions' => $regions,
            'continents' => $continents,
            'countries' => $countries,
            'country' => $country,
            'threshold' => $threshold,
            'confirmed_threshold' => $confirmedThreshold,
            'min_blocks' => $this->readiness->minBlocks(),
            'min_per_block' => $minPerBlock,
            'mod_scope_names' => $this->scopeProvider->describe($user, $this->scopeProvider->scopeFor($user)),
        ]);
    }

    #[Route('/moderate/regions/curated-default', name: 'moderate_regions_curated_default', methods: ['POST'])]
    public function setDefaultMode(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $regionId = $request->request->getInt('region');
        $mode = (string) $request->request->get('mode');
        if (!\in_array($mode, ['everything', 'confirmed', 'curated'], true)) {
            return $this->redirectToRoute('moderate_regions');
        }

        // Jurisdiction, then the gate. Both are re-checked here rather than
        // trusted from the rendered form: the desk hides an unavailable choice,
        // but a POST is a POST.
        if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($user), $regionId)) {
            throw $this->createAccessDeniedException('Region outside your moderation area.');
        }
        if ('curated' === $mode && !$this->readiness->isReady($regionId)) {
            $this->addFlash('danger', 'moderate_regions.flash_not_ready');

            return $this->redirectToRoute('moderate_regions');
        }
        if ('confirmed' === $mode
            && ($this->readiness->confirmedCounts([$regionId])[$regionId] ?? 0) < $this->readiness->confirmedThreshold()) {
            $this->addFlash('danger', 'moderate_regions.flash_not_confirmed');

            return $this->redirectToRoute('moderate_regions');
        }

        $this->db->executeStatement(
            'UPDATE region SET default_map_mode = :v WHERE id = :id',
            ['v' => $mode, 'id' => $regionId],
        );
        $this->addFlash('success', 'moderate_regions.flash_mode_'.$mode);

        return $this->redirectToRoute('moderate_regions');
    }

    /**
     * The about-text editor for one region: five locale slots, each with the
     * harvested Wikipedia lead shown beside the box a curator types into.
     *
     * Its own page rather than a control on the desk card. The desk is a list
     * of nineteen countries of regions and each row already carries a meter,
     * six block counts and three mode buttons; five textareas per row would
     * bury the setting it exists for. A page also gives the harvested text
     * somewhere to be READ, which is the thing a curator needs in front of
     * them to decide whether they are adapting it or replacing it.
     */
    #[Route('/moderate/regions/{slug}/about', name: 'moderate_regions_about', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function about(string $slug, TranslatorInterface $translator): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $region = $this->regionForCurator($slug, $user);

        $wiki = self::decodeJson($region['context']);
        $curated = self::decodeJson($region['context_curated']);

        $locales = [];
        foreach (RegionLead::LOCALES as $locale) {
            $own = RegionLead::override($curated, $locale);
            $harvest = $wiki[$locale] ?? null;
            $locales[] = [
                'code' => $locale,
                'text' => $own['text'] ?? '',
                'derived' => $own['derived'] ?? false,
                // What the harvest holds for this locale, so the curator can
                // read the thing they are about to override or adapt.
                'wiki' => \is_array($harvest) && \is_string($harvest['extract'] ?? null)
                    ? ['extract' => $harvest['extract'], 'url' => (string) ($harvest['url'] ?? ''), 'title' => (string) ($harvest['title'] ?? '')]
                    : null,
                // "I adapted the article" can only be claimed where an article
                // exists to adapt - this locale's, or English (translating the
                // English lead is a derivative work too).
                'canDerive' => RegionLead::hasSource($wiki, $locale),
            ];
        }

        return $this->render('moderate_regions/about.html.twig', [
            'page_title' => 'moderate_regions.about.title',
            'nav_active' => 'moderate',
            'region' => [
                'id' => $region['id'],
                'slug' => $slug,
                'label' => $translator->trans('region.'.$slug.'.label'),
                'countryCode' => $region['country_code'],
            ],
            'locales' => $locales,
            'max_len' => self::ABOUT_MAX,
            // The shell chrome names the curator's areas, the same as on the
            // desk this page is reached from.
            'mod_scope_names' => $this->scopeProvider->describe($user, $this->scopeProvider->scopeFor($user)),
        ]);
    }

    #[Route('/moderate/regions/{slug}/about', name: 'moderate_regions_about_save', requirements: ['slug' => '[a-z0-9-]+'], methods: ['POST'])]
    public function saveAbout(string $slug, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid(self::CSRF_ABOUT_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        // Jurisdiction re-checked on the POST, not trusted from the form that
        // rendered it — the same rule the mode buttons follow.
        $region = $this->regionForCurator($slug, $user);
        $wiki = self::decodeJson($region['context']);

        $curated = [];
        $now = new \DateTimeImmutable();
        foreach (RegionLead::LOCALES as $locale) {
            $text = trim((string) $request->request->get('text_'.$locale, ''));
            if ('' === $text) {
                // An emptied box REMOVES the override rather than storing "",
                // which is how a curator undoes one: the harvested lead comes
                // back on the next render, and nothing has to be re-imported.
                continue;
            }
            if (mb_strlen($text) > self::ABOUT_MAX) {
                $this->addFlash('danger', 'moderate_regions.about.flash_too_long');

                return $this->redirectToRoute('moderate_regions_about', ['slug' => $slug]);
            }
            // A claim of adaptation is only honoured where there is something
            // to adapt; otherwise it silently becomes original work, which is
            // the safe direction (no citation is better than a false one).
            $derived = $request->request->has('derived_'.$locale) && RegionLead::hasSource($wiki, $locale);
            $curated[$locale] = [
                'text' => $text,
                'derived' => $derived,
                'userId' => $user->getId(),
                'at' => $now->format(\DateTimeInterface::ATOM),
            ];
        }

        $this->db->executeStatement(
            'UPDATE region SET context_curated = :ctx, updated_at = NOW() WHERE id = :id',
            // All five boxes emptied means "no override at all" — NULL, not an
            // empty object, so the column reads the same as a region nobody has
            // ever edited.
            ['ctx' => [] === $curated ? null : json_encode($curated, \JSON_THROW_ON_ERROR), 'id' => $region['id']],
        );
        $this->addFlash('success', 'moderate_regions.about.flash_saved');

        return $this->redirectToRoute('moderate_regions_about', ['slug' => $slug]);
    }

    /**
     * One region by slug, refused unless it is inside this curator's areas and
     * is a region the desk operates on at all.
     *
     * @return array{id: int, country_code: string, context: ?string, context_curated: ?string}
     */
    private function regionForCurator(string $slug, User $user): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, country_code, context, context_curated FROM region
              WHERE slug = :slug AND geom IS NOT NULL AND country_code <> \'\''
            .' AND '.OperationalRegions::predicate('region'),
            ['slug' => $slug],
        );
        if (false === $row) {
            throw $this->createNotFoundException('No such region.');
        }
        $id = (int) $row['id'];
        if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($user), $id)) {
            throw $this->createAccessDeniedException('Region outside your moderation area.');
        }

        return [
            'id' => $id,
            'country_code' => (string) $row['country_code'],
            'context' => \is_string($row['context']) ? $row['context'] : null,
            'context_curated' => \is_string($row['context_curated']) ? $row['context_curated'] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJson(?string $raw): ?array
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        $decoded = json_decode($raw, true);

        /* @var array<string, mixed>|null */
        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * The regions this curator may act on, in the registry's own order.
     *
     * @return list<array{id: int, slug: string, countryCode: string, defaultMode: string, continent: string, countryName: string, flag: ?string}>
     */
    private function visibleRegions(User $user): array
    {
        $scope = $this->scopeProvider->scopeFor($user);
        /* Continent and country come from the World reference bundle, LEFT
           JOINed so a region whose country is somehow not in it still lists
           (under the "-" group) rather than vanishing from a moderation
           surface. Sorted in SQL rather than in PHP because a PHP sort would
           need the translator (region labels are translated) and would have to
           run before the grouping anyway. */
        $sql = "SELECT r.id, r.slug, r.country_code AS cc, r.default_map_mode,
                       COALESCE(cont.name, '') AS continent,
                       COALESCE(wc.name, r.country_code) AS country_name,
                       wc.iso2 AS world_iso2
                  FROM region r
             LEFT JOIN world_country wc ON wc.iso2 = r.country_code
             LEFT JOIN world_continent cont ON cont.id = wc.continent_id
                 WHERE r.geom IS NOT NULL AND r.country_code <> ''"
            .' AND '.OperationalRegions::predicate('r');
        $params = [];
        $types = [];
        if (!$scope->global) {
            $clauses = [];
            if ([] !== $scope->regionIds) {
                $clauses[] = 'r.id IN (:rids)';
                $params['rids'] = $scope->regionIds;
                $types['rids'] = ArrayParameterType::INTEGER;
            }
            if ([] !== $scope->countryCodes) {
                $clauses[] = 'UPPER(r.country_code) IN (:ccs)';
                $params['ccs'] = $scope->countryCodes;
                $types['ccs'] = ArrayParameterType::STRING;
            }
            // A limited scope with neither list is a curator assigned nothing
            // resolvable — show no regions rather than silently showing all.
            $sql .= ' AND ('.([] === $clauses ? 'FALSE' : implode(' OR ', $clauses)).')';
        }
        /* Continent, then country, then the biggest region first — the grouping
           the page renders (owner request 2026-08-14: "group this per continent
           and country and state"). A flat `area_km2 DESC` put Western Australia,
           Queensland and Québec adjacent, which reads as an ordering accident
           when the list spans six continents.

           Area DESC is KEPT as the within-country order rather than swapped for
           alphabetical: it is the existing convention, it needs no translation
           to sort by, and it puts the region a curator is most likely to be
           looking for at the top of its country. Empty continent sorts last so
           an unmatched country lands at the end, not above Africa. */
        $sql .= " ORDER BY NULLIF(cont.name, '') NULLS LAST, country_name, r.area_km2 DESC, r.slug";

        /** @var list<array{id: int|string, slug: string, cc: string, default_map_mode: string, continent: string, country_name: string, world_iso2: ?string}> $rows */
        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'countryCode' => (string) $r['cc'],
            'defaultMode' => (string) $r['default_map_mode'],
            'continent' => (string) $r['continent'],
            'countryName' => (string) $r['country_name'],
            // Only when the World bundle actually matched: a code with no
            // country row has no flag file either, and a broken image on a
            // moderation surface is worse than no flag.
            'flag' => null !== $r['world_iso2']
                ? 'flags/'.strtolower((string) $r['world_iso2']).'.svg'
                : null,
        ], $rows);
    }
}
