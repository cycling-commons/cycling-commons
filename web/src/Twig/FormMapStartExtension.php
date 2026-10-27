<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Catalog\RegionRegistryProvider;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What the add form needs to open its map where the rider looks (docs/specs/moderation-and-contribution.md §1.2,
 * "Where the form's map opens"): each region's camera box, so the browser can open on the region or country the
 * map last showed without sending that choice here, and the rider's own base location, which tailors their map
 * view.
 *
 * @api
 */
final class FormMapStartExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly RegionRegistryProvider $regions,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('form_map_start', $this->formMapStart(...)),
        ];
    }

    /**
     * @return array{regions: list<array{slug: string, cc: string, view: array{0: float, 1: float, 2: float, 3: float}}>, base: ?array{lat: float, lng: float}}
     */
    public function formMapStart(): array
    {
        $user = $this->security->getUser();
        $lat = $user instanceof User ? $user->getBaseLat() : null;
        $lng = $user instanceof User ? $user->getBaseLng() : null;

        return [
            'regions' => array_map(
                static fn (array $r): array => ['slug' => $r['slug'], 'cc' => $r['countryCode'], 'view' => $r['view']],
                $this->regions->all(),
            ),
            'base' => null !== $lat && null !== $lng ? ['lat' => $lat, 'lng' => $lng] : null,
        ];
    }
}
