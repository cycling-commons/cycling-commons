<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Import\OsmLinker;
use App\Catalog\ItemType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A drawn road surface links itself to the OpenStreetMap roads under its line (edit-items/A-road-surface.md, "A
 * drawn road links itself"): the ways the line follows are its `waysSpanned`, the longest first, and the longest is
 * its `osm_ref`, which answers its OSM question by construction. On saving (CatalogContributionService) and on
 * approving (ModerationService), so a road saved before, or without an engine at hand, links when it is approved.
 *
 * @api
 */
final class DrawnRoadLinker
{
    public function __construct(
        private readonly RoadWayLocator $roadWays,
        private readonly OsmLinker $linker,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The ways a drawn road's line follows, as `waysSpanned`, the longest first; none without a line or an engine.
     *
     * @return array{waysSpanned?: list<string>}
     */
    public function waysFor(mixed $line): array
    {
        if (!\is_array($line) || \count($line) < 2) {
            return [];
        }
        $points = [];
        foreach ($line as $p) {
            if (\is_array($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null)) {
                $points[] = [(float) $p[0], (float) $p[1]];
            }
        }
        $ways = $this->roadWays->waysAlong($points);

        return [] === $ways ? [] : ['waysSpanned' => $ways];
    }

    /**
     * A new road surface linked to the way its line covers longest; false when it is answered already, is no road
     * surface, names no way, or another served row holds that way (then it queues, where a curator sees the clash).
     *
     * @param array<string, mixed> $attributes the item's attributes, with `waysSpanned`
     */
    public function link(Item $item, array $attributes): bool
    {
        $ways = $attributes['waysSpanned'] ?? null;
        $main = \is_array($ways) ? ($ways[0] ?? null) : null;
        if ($item->osmAnswered() || ItemType::RoadSurface->letter() !== $item->getLetter()
            || !\is_string($main) || 1 !== preg_match('~^way/\d{1,16}$~', $main)
            || $this->linker->refIsTaken($main, $item->getLetter(), (int) $item->getId())) {
            return false;
        }
        $item->answerOsm($main);
        $this->em->flush();

        return true;
    }

    /**
     * A waiting new road surface that is not linked yet: its ways are read from its line when it names none, kept on
     * the item, and the road is linked. For the approval of a road saved before roads linked themselves.
     */
    public function linkWaiting(Item $item): bool
    {
        if ($item->osmAnswered() || ItemType::RoadSurface->letter() !== $item->getLetter()) {
            return false;
        }
        $attributes = $item->getAttributes();
        if (!isset($attributes['waysSpanned'])) {
            $segment = $attributes['segment'] ?? null;
            $attributes += $this->waysFor(\is_array($segment) ? ($segment['line'] ?? null) : null);
            $item->setAttributes($attributes);
            $this->em->flush();
        }

        return $this->link($item, $attributes);
    }
}
