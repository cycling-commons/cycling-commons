<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Moderation\ModerationScopeProvider;
use App\Routing\LocalePrefix;
use App\Traffic\TrafficDisclosure;
use App\Traffic\TrafficView;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Declared traffic beside measured traffic, for curators
 * (docs/specs/traffic-measurements.md §4.6). A road-surface item that names an
 * OSM way and says Quiet, Moderate or Busy is listed when that way has a
 * measured group that passed the disclosure rules; nothing else appears.
 *
 * @api
 */
#[Route(LocalePrefix::PATHS)]
#[IsGranted('ROLE_CURATOR')]
final class ModerateTrafficController extends AbstractController
{
    #[Route('/moderate/traffic', name: 'moderate_traffic', methods: ['GET'])]
    public function index(TrafficView $view, Connection $db, ModerationScopeProvider $scopeProvider): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $scope = $scopeProvider->scopeFor($user);
        $groups = TrafficDisclosure::groupsOf($view->scheme());

        // Busier direction per way and group, as the map layer shows it. A
        // cycle path has no passing cars; its value is the cars on the road
        // beside it, marked nearby (noise, not safety).
        $measured = [];
        foreach ($view->shown() as $entry) {
            $nearby = 'p' === $entry['label'];
            $value = $nearby ? $entry['nearbyPerKm'] : $entry['carsPerKm'];
            $current = $measured[$entry['way']][$entry['group']] ?? null;
            if (null === $current || $value > $current['n']) {
                $measured[$entry['way']][$entry['group']] = ['n' => $value, 'nearby' => $nearby];
            }
        }

        $rows = [];
        foreach (array_chunk(array_keys($measured), 1000) as $ways) {
            $items = $db->fetchAllAssociative(
                "SELECT id, name, source_ref, region_id, attributes->>'traffic' AS declared
                   FROM item
                  WHERE letter = 'A' AND source_ref IN (:refs) AND attributes->>'traffic' IS NOT NULL
                  ORDER BY name",
                ['refs' => array_map(static fn (int $w): string => 'way/'.$w, $ways)],
                ['refs' => ArrayParameterType::STRING],
            );
            foreach ($items as $item) {
                if (!$scopeProvider->allowsRegion($scope, null !== $item['region_id'] ? (int) $item['region_id'] : null)) {
                    continue;
                }
                $way = (int) substr((string) $item['source_ref'], 4);
                $rows[] = [
                    'id' => (int) $item['id'], 'name' => (string) $item['name'], 'way' => $way,
                    'declared' => (string) $item['declared'], 'measured' => $measured[$way],
                ];
            }
        }

        return $this->render('moderate/traffic.html.twig', [
            'progress' => $view->progress(),
            // Country codes that have a flag in assets/flags, drawn before the country's name.
            'flags' => array_map(static fn (string $f): string => basename($f, '.svg'), glob($this->getParameter('kernel.project_dir').'/assets/flags/*.svg') ?: []),
            'built_at' => $view->builtAt(),
            'page_title' => 'meta.moderate_traffic_title',
            'groups' => $groups,
            'rows' => $rows,
        ]);
    }
}
