<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\SentryBeforeSend;
use App\Support\SentryNoise;
use PHPUnit\Framework\Attributes\DataProvider;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * GlitchTip hears about real faults and not about strangers being answered
 * correctly. Each noisy class also carries a fault, so the rule is per case,
 * not per class: a signed-in curator refused their own desk is a 403 that
 * must reach the tracker.
 */
final class SentryNoiseTest extends KernelTestCase
{
    private function noise(Request $request, ?TokenInterface $token = null): SentryNoise
    {
        $requests = new RequestStack();
        $requests->push($request);
        $tokens = new TokenStorage();
        $tokens->setToken($token);
        $trust = self::getContainer()->get('security.authentication.trust_resolver');
        self::assertInstanceOf(AuthenticationTrustResolverInterface::class, $trust);

        return new SentryNoise($requests, $tokens, $trust);
    }

    private static function request(string $host, ?string $referer = null): Request
    {
        $request = Request::create('/en/map');
        $request->headers->set('host', $host);
        if (null !== $referer) {
            $request->headers->set('referer', $referer);
        }

        return $request;
    }

    /** As the kernel hands it to the bundle: the 400 wrapping the cause. */
    private static function untrustedHost(string $host): \Throwable
    {
        $cause = new SuspiciousOperationException(\sprintf('Untrusted Host "%s".', $host));

        return new BadRequestHttpException($cause->getMessage(), $cause);
    }

    private static function rider(): InMemoryUser
    {
        return new InMemoryUser('rider@example.test', null, ['ROLE_USER']);
    }

    /** @return iterable<string, array{string}> */
    public static function scannerHosts(): iterable
    {
        yield 'the load balancer address' => ['142.132.241.155'];
        yield 'an address with a port' => ['142.132.241.155:8080'];
        yield 'an IPv6 literal' => ['[2a01:4f8::1]'];
        yield 'not a host name' => ['a_b<c>'];
    }

    #[DataProvider('scannerHosts')]
    public function testAScannerNamingAnAddressIsNoise(string $host): void
    {
        self::assertTrue($this->noise(self::request($host))->isNoise(self::untrustedHost($host)));
    }

    /** A DNS record pointing at us that TRUSTED_HOSTS does not list. */
    public function testAnUnlistedNameIsReported(): void
    {
        $host = 'api.cyclingcommons.org';

        self::assertFalse($this->noise(self::request($host))->isNoise(self::untrustedHost($host)));
    }

    public function testABotsNotFoundIsNoise(): void
    {
        $noise = $this->noise(self::request('cyclingcommons.org'));

        self::assertTrue($noise->isNoise(new NotFoundHttpException()));
    }

    public function testANotFoundLinkedFromElsewhereIsNoise(): void
    {
        $noise = $this->noise(self::request('cyclingcommons.org', 'https://example.org/old-list'));

        self::assertTrue($noise->isNoise(new NotFoundHttpException()));
    }

    /** One of our own pages links to something that is not there. */
    public function testANotFoundLinkedFromOurOwnPageIsReported(): void
    {
        $noise = $this->noise(self::request('cyclingcommons.org', 'https://cyclingcommons.org/en/climbs'));

        self::assertFalse($noise->isNoise(new NotFoundHttpException()));
    }

    public function testAVisitorNotSignedInIsNoise(): void
    {
        self::assertTrue($this->noise(self::request('cyclingcommons.org'))->isNoise(new AccessDeniedException()));
    }

    /** The firewall sends both to the login page, not to a 403. */
    public function testARememberedOrHalfSignedInVisitorIsNoise(): void
    {
        $remembered = new RememberMeToken(self::rider(), 'main');
        $halfway = new TwoFactorToken(new UsernamePasswordToken(self::rider(), 'main', ['ROLE_USER']), null, 'main', ['totp']);

        self::assertTrue($this->noise(self::request('cyclingcommons.org'), $remembered)->isNoise(new AccessDeniedException()));
        self::assertTrue($this->noise(self::request('cyclingcommons.org'), $halfway)->isNoise(new AccessDeniedException()));
    }

    /** A 403: can be a real permission mistake. */
    public function testASignedInVisitorRefusedIsReported(): void
    {
        $token = new UsernamePasswordToken(self::rider(), 'main', ['ROLE_USER']);

        self::assertFalse($this->noise(self::request('cyclingcommons.org'), $token)->isNoise(new AccessDeniedException()));
    }

    public function testAnyOtherErrorIsReported(): void
    {
        self::assertFalse($this->noise(self::request('142.132.241.155'))->isNoise(new \RuntimeException('a bug')));
    }

    /** A Messenger worker or a console command has no visitor to blame. */
    public function testOutsideARequestEverythingIsReported(): void
    {
        $trust = self::getContainer()->get('security.authentication.trust_resolver');
        self::assertInstanceOf(AuthenticationTrustResolverInterface::class, $trust);
        $noise = new SentryNoise(new RequestStack(), new TokenStorage(), $trust);

        self::assertFalse($noise->isNoise(new NotFoundHttpException()));
        self::assertFalse($noise->isNoise(new AccessDeniedException()));
    }

    /** The client's own ignore list would drop by class before the hook runs. */
    public function testTheClientDropsNothingByClassAlone(): void
    {
        $client = self::getContainer()->get(HubInterface::class)->getClient();
        self::assertNotNull($client);

        self::assertSame([], $client->getOptions()->getIgnoreExceptions());
        self::assertInstanceOf(SentryBeforeSend::class, $client->getOptions()->getBeforeSendCallback());
    }

    public function testTheHookDropsNoiseAndKeepsTheRest(): void
    {
        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push(self::request('142.132.241.155'));
        $hook = self::getContainer()->get(SentryBeforeSend::class);
        self::assertInstanceOf(SentryBeforeSend::class, $hook);

        self::assertNull($hook(Event::createEvent(), EventHint::fromArray(['exception' => self::untrustedHost('142.132.241.155')])));
        $kept = $hook(Event::createEvent(), EventHint::fromArray(['exception' => new \RuntimeException('a bug')]));
        self::assertNotNull($kept);
        self::assertStringStartsWith('cyclingcommons@', (string) $kept->getRelease());
    }
}
