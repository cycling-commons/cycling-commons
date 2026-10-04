<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Yaml\Yaml;

/**
 * The password_reauth limiter, made countable across requests in a functional test.
 *
 * The test env backs every limiter with the array cache adapter, and the
 * services resetter clears it at the start of each request, so tries spent in
 * one request are gone by the next. This swaps in the same limiter, built from
 * the same rate_limiter.yaml entry, over a storage nothing resets. Install it
 * before the first request, on a client with reboot disabled.
 */
final class PasswordReauthBudget
{
    /** @return int how many tries the budget allows */
    public static function install(ContainerInterface $container): int
    {
        $parsed = Yaml::parseFile(__DIR__.'/../../config/packages/rate_limiter.yaml');
        \assert(\is_array($parsed));
        /** @var array{policy: string, limit: int, interval: string} $config */
        $config = $parsed['framework']['rate_limiter']['password_reauth'];

        $container->set('limiter.password_reauth', new RateLimiterFactory(
            ['id' => 'password_reauth', 'policy' => $config['policy'], 'limit' => $config['limit'], 'interval' => $config['interval']],
            new InMemoryStorage(),
        ));

        return $config['limit'];
    }
}
