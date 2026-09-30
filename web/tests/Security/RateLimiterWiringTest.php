<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Every limiter in rate_limiter.yaml guards something.
 *
 * A limiter nothing injects reads like protection in a review and protects
 * nothing, and the §7 inventory then documents a guard that does not exist.
 * A limiter counts as wired when a service asks for it by its autowiring name
 * (`$<camelName>Limiter`), by `#[Target('<name>')]`, or by its service id
 * (`limiter.<name>`) in PHP or in config. Its cache pool counts as used when a
 * limiter names it or the code borrows it.
 *
 * @see docs/specs/security-architecture.md §7
 */
final class RateLimiterWiringTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $parsed = Yaml::parseFile(self::ROOT.'/config/packages/rate_limiter.yaml');
        self::assertIsArray($parsed);

        return $parsed;
    }

    /** Every PHP file under src/ and every YAML file under config/ except this one, as one haystack. */
    private static function haystack(): string
    {
        $out = '';
        foreach ((new Finder())->files()->in(self::ROOT.'/src')->name('*.php') as $file) {
            $out .= $file->getContents()."\n";
        }
        foreach ((new Finder())->files()->in(self::ROOT.'/config')->name(['*.yaml', '*.yml', '*.php']) as $file) {
            if ('rate_limiter.yaml' !== $file->getFilename()) {
                $out .= $file->getContents()."\n";
            }
        }

        return $out;
    }

    public function testEveryLimiterIsInjectedSomewhere(): void
    {
        /** @var array<string, array<string, mixed>> $limiters */
        $limiters = self::config()['framework']['rate_limiter'] ?? [];
        self::assertNotEmpty($limiters);

        $haystack = self::haystack();
        $dead = [];
        foreach (array_keys($limiters) as $name) {
            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $name))));
            $wired = 1 === preg_match('/\$'.preg_quote($camel, '/').'Limiter\b/i', $haystack)
                || str_contains($haystack, "Target('".$name."')")
                || str_contains($haystack, 'limiter.'.$name."'")
                || str_contains($haystack, 'limiter.'.$name.'"')
                || 1 === preg_match('/limiter\.'.preg_quote($name, '/').'\s*$/m', $haystack);
            if (!$wired) {
                $dead[] = $name;
            }
        }

        self::assertSame([], $dead, 'rate limiters nothing injects: remove them or wire them');
    }

    public function testEveryLimiterPoolIsUsed(): void
    {
        $config = self::config();
        /** @var array<string, mixed> $pools */
        $pools = $config['framework']['cache']['pools'] ?? [];
        /** @var array<string, array<string, mixed>> $limiters */
        $limiters = $config['framework']['rate_limiter'] ?? [];
        $named = array_map(static fn (array $l): string => (string) ($l['cache_pool'] ?? ''), $limiters);

        $haystack = self::haystack();
        $unused = [];
        foreach (array_keys($pools) as $pool) {
            if (!\in_array($pool, $named, true) && !str_contains($haystack, $pool)) {
                $unused[] = $pool;
            }
        }

        self::assertSame([], $unused, 'cache pools no limiter and no service uses');

        // The test env's array-adapter overrides name only pools that exist.
        /** @var array<string, mixed> $testPools */
        $testPools = $config['when@test']['framework']['cache']['pools'] ?? [];
        self::assertSame([], array_values(array_diff(array_keys($testPools), array_keys($pools))), 'when@test overrides a pool that is not defined');
    }
}
