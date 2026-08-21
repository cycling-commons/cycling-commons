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
 * Curator Regions desk — the only writer of `region.default_map_mode`. Gated: cannot enable a mode the region cannot show.
 *
 * @see docs/specs/map-and-search.md §4.2
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateRegionsController extends AbstractController
{
    private const string CSRF_TOKEN_ID = 'region-curated-default';
    private const string CSRF_ABOUT_ID = 'region-about-text';

    /** Lead paragraph cap. */
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

        $reports = $this->readiness->reportForRegions(array_map(static fn (array $r): int => $r['id'], $rows));
        $confirmedCounts = $this->readiness->confirmedCounts(array_map(static fn (array $r): int => $r['id'], $rows));
        $confirmedThreshold = $this->readiness->confirmedThreshold();
        $threshold = $this->readiness->threshold();
        $minPerBlock = $this->readiness->minPerBlock();

        $regions = array_map(static function (array $r) use ($reports, $minPerBlock, $translator, $confirmedCounts, $confirmedThreshold): array {
            $rep = $reports[$r['id']];
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
                'canCurated' => 'curated' === $r['defaultMode'] || $rep['ready'],
            ];
        }, $rows);

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

        // Re-check scope and gate on POST — do not trust the rendered form.
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
                'wiki' => \is_array($harvest) && \is_string($harvest['extract'] ?? null)
                    ? ['extract' => $harvest['extract'], 'url' => (string) ($harvest['url'] ?? ''), 'title' => (string) ($harvest['title'] ?? '')]
                    : null,
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
                'countryName' => $region['country_name'],
                'flag' => null !== $region['world_iso2']
                    ? 'flags/'.strtolower($region['world_iso2']).'.svg'
                    : null,
            ],
            'locales' => $locales,
            'max_len' => self::ABOUT_MAX,
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
        $region = $this->regionForCurator($slug, $user);
        $wiki = self::decodeJson($region['context']);

        $curated = [];
        $now = new \DateTimeImmutable();
        foreach (RegionLead::LOCALES as $locale) {
            $text = trim((string) $request->request->get('text_'.$locale, ''));
            if ('' === $text) {
                continue;
            }
            if (mb_strlen($text) > self::ABOUT_MAX) {
                $this->addFlash('danger', 'moderate_regions.about.flash_too_long');

                return $this->redirectToRoute('moderate_regions_about', ['slug' => $slug]);
            }
            // Adaptation claim only if a source exists; otherwise original (fail-closed).
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
            ['ctx' => [] === $curated ? null : json_encode($curated, \JSON_THROW_ON_ERROR), 'id' => $region['id']],
        );
        $this->addFlash('success', 'moderate_regions.about.flash_saved');

        return $this->redirectToRoute('moderate_regions_about', ['slug' => $slug]);
    }

    /**
     * One region by slug; 404/403 unless it is in this curator's areas.
     *
     * @return array{id: int, country_code: string, country_name: string, world_iso2: ?string, context: ?string, context_curated: ?string}
     */
    private function regionForCurator(string $slug, User $user): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT r.id, r.country_code, r.context, r.context_curated,
                    COALESCE(wc.name, r.country_code) AS country_name, wc.iso2 AS world_iso2
               FROM region r
          LEFT JOIN world_country wc ON wc.iso2 = r.country_code
              WHERE r.slug = :slug AND r.geom IS NOT NULL AND r.country_code <> \'\''
            .' AND '.OperationalRegions::predicate('r'),
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
            'country_name' => (string) $row['country_name'],
            'world_iso2' => \is_string($row['world_iso2']) ? $row['world_iso2'] : null,
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
     * @return list<array{id: int, slug: string, countryCode: string, defaultMode: string, continent: string, countryName: string, flag: ?string}>
     */
    private function visibleRegions(User $user): array
    {
        $scope = $this->scopeProvider->scopeFor($user);
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
            // Empty assignment: show nothing, never silently all regions.
            $sql .= ' AND ('.([] === $clauses ? 'FALSE' : implode(' OR ', $clauses)).')';
        }
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
            'flag' => null !== $r['world_iso2']
                ? 'flags/'.strtolower((string) $r['world_iso2']).'.svg'
                : null,
        ], $rows);
    }
}
