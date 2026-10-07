<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;

/**
 * The TRUSTED_HOSTS each deployed environment commits (security-architecture.md
 * section 8): its site and its API name are served, and nothing else. A name
 * left out answers 400 to everyone who uses it; a pattern too loose lets a
 * mail link carry a reset token to a foreign host. Read from the committed
 * files, never a `.local` override, and checked with the same Request code the
 * framework runs.
 */
final class DeployedTrustedHostsTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
    }

    /** @return list<string> */
    private static function patterns(string $file): array
    {
        $env = (string) file_get_contents(\dirname(__DIR__, 2).'/'.$file);
        self::assertSame(1, preg_match("/^TRUSTED_HOSTS='([^']*)'$/m", $env, $m), $file.' sets TRUSTED_HOSTS');

        return explode(',', $m[1]);
    }

    private static function serves(string $file, string $host): bool
    {
        Request::setTrustedHosts(self::patterns($file));
        $request = Request::create('https://'.$host.'/v1/search?q=utrecht');

        try {
            return $host === $request->getHost();
        } catch (SuspiciousOperationException) {
            return false;
        }
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function hosts(): iterable
    {
        yield 'prod site' => ['.env.prod', 'cyclingcommons.org', true];
        yield 'prod www' => ['.env.prod', 'www.cyclingcommons.org', true];
        yield 'prod API' => ['.env.prod', 'api.cyclingcommons.org', true];
        yield 'prod health probe' => ['.env.prod', '10.0.1.12', true];
        yield 'prod refuses staging' => ['.env.prod', 'staging.cyclingcommons.org', false];
        yield 'prod refuses the staging API' => ['.env.prod', 'staging-api.cyclingcommons.org', false];
        yield 'prod refuses a lookalike' => ['.env.prod', 'api.cyclingcommons.org.evil.example', false];
        yield 'prod refuses the load balancer address' => ['.env.prod', '142.132.241.155', false];
        yield 'staging site' => ['.env.staging', 'staging.cyclingcommons.org', true];
        yield 'staging API' => ['.env.staging', 'staging-api.cyclingcommons.org', true];
        yield 'staging health probe' => ['.env.staging', '10.0.1.12', true];
        yield 'staging refuses prod' => ['.env.staging', 'cyclingcommons.org', false];
        yield 'staging refuses the prod API' => ['.env.staging', 'api.cyclingcommons.org', false];
        yield 'staging refuses a lookalike' => ['.env.staging', 'evil-staging-api.cyclingcommons.org', false];
    }

    #[DataProvider('hosts')]
    public function testEachEnvironmentServesItsOwnHostsOnly(string $file, string $host, bool $served): void
    {
        self::assertSame($served, self::serves($file, $host));
    }
}
