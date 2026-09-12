<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * How much of the published contract actually answers.
 *
 * The reference page used to assert "Draft contract, API not live yet", which
 * stopped being true the day `/v1/map-config` shipped and nobody edited the
 * banner (known issue, 2026-09-06). A contract page that is wrong about itself
 * is the worst kind, so the number is counted rather than written: the total
 * from the OpenAPI document the page renders, the live half from the router.
 * Shipping an endpoint updates the sentence.
 *
 * @see docs/specs/public-api.md §2.3
 *
 * @api
 */
final readonly class ApiSurface
{
    /** Every public v1 route carries this prefix (`App\Controller\Api\V1`). */
    private const string ROUTE_PREFIX = 'api_v1_';

    public function __construct(
        private RouterInterface $router,
        #[Autowire('%kernel.project_dir%/public/api/openapi.yaml')]
        private string $contractPath,
    ) {
    }

    /** Endpoints that answer today. */
    public function live(): int
    {
        $n = 0;
        foreach (array_keys($this->router->getRouteCollection()->all()) as $name) {
            if (str_starts_with($name, self::ROUTE_PREFIX)) {
                ++$n;
            }
        }

        return $n;
    }

    /** Endpoints the published contract describes. */
    public function promised(): int
    {
        if (!is_file($this->contractPath)) {
            return 0;
        }
        /** @var array{paths?: array<string, mixed>} $doc */
        $doc = Yaml::parseFile($this->contractPath) ?? [];

        return \count($doc['paths'] ?? []);
    }
}
