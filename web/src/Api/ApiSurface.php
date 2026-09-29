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
        return \count($this->paths());
    }

    /**
     * The paths that answer today, for an error body that says what to use.
     *
     * @return list<string>
     */
    public function livePaths(): array
    {
        $paths = [];
        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            if (str_starts_with($name, self::ROUTE_PREFIX)) {
                $paths[] = $route->getPath();
            }
        }
        sort($paths);

        return array_values(array_unique($paths));
    }

    /**
     * Does the contract describe this method and path? `{id}` matches one
     * path segment, everything else literally (`/v1/routes/{id}.gpx`).
     */
    public function describes(string $method, string $path): bool
    {
        foreach ($this->paths() as $template => $operations) {
            $pattern = '~^'.preg_replace('~\\\\\{[^/}]+\\\\\}~', '[^/]+', preg_quote($template, '~')).'$~';
            if (1 === preg_match($pattern, $path)
                && \is_array($operations)
                && \array_key_exists(strtolower($method), $operations)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function paths(): array
    {
        if (!is_file($this->contractPath)) {
            return [];
        }
        /** @var array{paths?: array<string, mixed>} $doc */
        $doc = Yaml::parseFile($this->contractPath) ?? [];

        return $doc['paths'] ?? [];
    }
}
