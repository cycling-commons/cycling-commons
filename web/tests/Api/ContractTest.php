<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\ApiSurface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The published contract and the router describe the same API
 * (public-api.md §2.3), in both directions: every method and path in
 * openapi.yaml reaches a built endpoint, not the "planned" fallback, and
 * every built endpoint is in the contract.
 */
final class ContractTest extends KernelTestCase
{
    /** A value for each path placeholder, shaped as its route requires. */
    private const array SAMPLES = ['{id}' => '1', '{ref}' => 'u-1'];

    public function testEveryPathInTheContractAnswers(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);
        $context = $router->getContext();

        foreach ($this->operations() as [$method, $template]) {
            $path = strtr($template, self::SAMPLES);
            // A region is addressed by slug.
            if (str_starts_with($template, '/v1/regions/')) {
                $path = str_replace('/v1/regions/1', '/v1/regions/wallonia', $path);
            }
            $router->setContext((new RequestContext())->fromRequest(\Symfony\Component\HttpFoundation\Request::create($path, $method)));
            try {
                $match = $router->match($path);
            } catch (ResourceNotFoundException) {
                $match = ['_route' => 'none'];
            }
            self::assertStringStartsWith('api_v1_', (string) $match['_route'], $method.' '.$template.' is in the contract but no endpoint answers it');
        }
        $router->setContext($context);
    }

    public function testEveryEndpointIsInTheContract(): void
    {
        self::bootKernel();
        $surface = self::getContainer()->get(ApiSurface::class);
        $router = self::getContainer()->get(RouterInterface::class);

        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($name, 'api_v1_')) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                $path = strtr($route->getPath(), self::SAMPLES);
                self::assertTrue($surface->describes($method, $path), $method.' '.$route->getPath().' answers but the contract does not describe it');
            }
        }
        self::assertSame($surface->promised(), \count($this->paths()));
    }

    /** @return list<array{0: string, 1: string}> */
    private function operations(): array
    {
        $out = [];
        foreach ($this->paths() as $template => $operations) {
            foreach (array_keys((array) $operations) as $method) {
                if (\in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $out[] = [strtoupper($method), (string) $template];
                }
            }
        }
        self::assertNotSame([], $out);

        return $out;
    }

    /** @return array<string, mixed> */
    private function paths(): array
    {
        /** @var array{paths: array<string, mixed>} $doc */
        $doc = Yaml::parseFile(self::getContainer()->getParameter('kernel.project_dir').'/public/api/openapi.yaml');

        return $doc['paths'];
    }
}
