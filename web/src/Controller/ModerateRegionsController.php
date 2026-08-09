<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Controller;

use App\Catalog\CuratedReadiness;
use App\Catalog\OperationalRegions;
use App\Entity\User;
use App\Moderation\ModerationScopeProvider;
use App\Pagination\Pager;
use App\Routing\LocalePrefix;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Curator Regions desk — the only place `region.curated_default` is set.
 *
 * The toggle is GATED, which is the whole point (owner decision "B"): a region
 * cannot be made to open in Curated mode until it actually has enough curated
 * best-of content, so the flag can never be set prematurely and recreate the
 * empty-map trap the global default was flipped to avoid. The readiness count
 * is shown next to the threshold whether or not the gate is open, so a curator
 * can see how far off a region is.
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
    /** Regions per page. Matches the submission and route desks. */
    public const int PER_PAGE = 25;

    private const string CSRF_TOKEN_ID = 'region-curated-default';

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

        // Sliced BEFORE the readiness reports are built, not after: a global
        // curator sees every onboarded region on earth (Japan alone is 47),
        // and every one of them costs a content count. The page is what gets
        // measured.
        $pager = Pager::of($request->query->getInt('page', 1), \count($rows), self::PER_PAGE);
        $rows = \array_slice($rows, $pager['offset'], $pager['perPage']);

        $reports = $this->readiness->reportForRegions(array_map(static fn (array $r): int => $r['id'], $rows));
        $threshold = $this->readiness->threshold();
        $minPerBlock = $this->readiness->minPerBlock();

        $regions = array_map(static function (array $r) use ($reports, $minPerBlock, $translator): array {
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
                'label' => $translator->trans('region.'.$r['slug'].'.label'),
                'curatedDefault' => $r['curatedDefault'],
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
                'canToggle' => $r['curatedDefault'] || $rep['ready'],
            ];
        }, $rows);

        return $this->render('moderate_regions/index.html.twig', [
            'page_title' => 'moderate_regions.title',
            'page_description' => 'moderate_regions.lead',
            'nav_active' => 'moderate',
            'regions' => $regions,
            'countries' => $countries,
            'country' => $country,
            'threshold' => $threshold,
            'min_blocks' => $this->readiness->minBlocks(),
            'min_per_block' => $minPerBlock,
            'pager' => $pager,
            'pager_params' => '' === $country ? [] : ['country' => $country],
            'mod_scope_names' => $this->scopeProvider->describe($user, $this->scopeProvider->scopeFor($user)),
        ]);
    }

    #[Route('/moderate/regions/curated-default', name: 'moderate_regions_curated_default', methods: ['POST'])]
    public function setCuratedDefault(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $regionId = $request->request->getInt('region');
        $enable = '1' === (string) $request->request->get('enable');

        // Jurisdiction, then the gate. Both are re-checked here rather than
        // trusted from the rendered form: the desk hides an unavailable toggle,
        // but a POST is a POST.
        if (!$this->scopeProvider->allowsRegion($this->scopeProvider->scopeFor($user), $regionId)) {
            throw $this->createAccessDeniedException('Region outside your moderation area.');
        }
        if ($enable && !$this->readiness->isReady($regionId)) {
            $this->addFlash('danger', 'moderate_regions.flash_not_ready');

            return $this->redirectToRoute('moderate_regions');
        }

        $this->db->executeStatement(
            'UPDATE region SET curated_default = :v WHERE id = :id',
            ['v' => $enable, 'id' => $regionId],
            // ParameterType::BOOLEAN, not PDO::PARAM_BOOL: DBAL 4 types are its
            // own enum and an int here fatals inside ExpandArrayParameters.
            ['v' => ParameterType::BOOLEAN],
        );
        $this->addFlash('success', $enable ? 'moderate_regions.flash_enabled' : 'moderate_regions.flash_disabled');

        return $this->redirectToRoute('moderate_regions');
    }

    /**
     * The regions this curator may act on, in the registry's own order.
     *
     * @return list<array{id: int, slug: string, countryCode: string, curatedDefault: bool}>
     */
    private function visibleRegions(User $user): array
    {
        $scope = $this->scopeProvider->scopeFor($user);
        $sql = "SELECT id, slug, country_code AS cc, curated_default
                  FROM region
                 WHERE geom IS NOT NULL AND country_code <> ''"
            .' AND '.OperationalRegions::predicate('region');
        $params = [];
        $types = [];
        if (!$scope->global) {
            $clauses = [];
            if ([] !== $scope->regionIds) {
                $clauses[] = 'id IN (:rids)';
                $params['rids'] = $scope->regionIds;
                $types['rids'] = ArrayParameterType::INTEGER;
            }
            if ([] !== $scope->countryCodes) {
                $clauses[] = 'UPPER(country_code) IN (:ccs)';
                $params['ccs'] = $scope->countryCodes;
                $types['ccs'] = ArrayParameterType::STRING;
            }
            // A limited scope with neither list is a curator assigned nothing
            // resolvable — show no regions rather than silently showing all.
            $sql .= ' AND ('.([] === $clauses ? 'FALSE' : implode(' OR ', $clauses)).')';
        }
        $sql .= ' ORDER BY area_km2 DESC, slug';

        /** @var list<array{id: int|string, slug: string, cc: string, curated_default: bool}> $rows */
        $rows = $this->db->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'countryCode' => (string) $r['cc'],
            'curatedDefault' => (bool) $r['curated_default'],
        ], $rows);
    }
}
