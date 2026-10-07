<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Entity\User;
use App\Traffic\TrafficStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * POST /scout/traffic (docs/specs/traffic-measurements.md §4.1, §4.3).
 */
final class TrafficControllerTest extends WebTestCase
{
    private function rider(KernelBrowser $client, string $tag): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail("traffic-{$tag}@example.com");
        $user->setPassword('x');
        $user->setDisplayName('Radar rider');
        $user->setEmailVerified(true);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return $user;
    }

    /** @param array<string, mixed>|string $body */
    private function post(KernelBrowser $client, array|string $body, ?string $token = null): array
    {
        $token ??= static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('scout-traffic')->getValue();
        $client->request('POST', '/scout/traffic', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CC_TOKEN' => $token,
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $client->getResponse()->getContent(), true) ?? [];
    }

    /** A line dated today, so the plausibility rules accept it whatever day the suite runs. */
    private static function line(array $over = []): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $q = 'Q'.(intdiv((int) $now->format('n') - 1, 3) + 1);

        return $over + TrafficLineTest::line([
            'quarter' => $now->format('Y').'-'.$q,
            'day' => intdiv($now->getTimestamp(), 86400),
            'blocks' => [bin2hex(random_bytes(32))],
        ]);
    }

    public function testASignedOutVisitorCannotSend(): void
    {
        $client = static::createClient();
        $client->request('POST', '/scout/traffic', [], [], ['CONTENT_TYPE' => 'application/json'], '{"v":1,"lines":[]}');

        self::assertContains($client->getResponse()->getStatusCode(), [302, 401]);
    }

    public function testAForeignTokenIsRefused(): void
    {
        $client = static::createClient();
        $this->rider($client, 'token');
        $this->post($client, ['v' => 1, 'lines' => [self::line()]], 'not-the-token');

        self::assertResponseStatusCodeSame(403);
    }

    public function testATrackShapedPayloadIsRefusedWhole(): void
    {
        $client = static::createClient();
        $this->rider($client, 'track');
        $body = $this->post($client, ['v' => 1, 'lines' => [self::line(['lat' => 52.0])]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('track_not_accepted', $body['error']);
        self::assertCount(0, iterator_to_array(static::getContainer()->get(TrafficStore::class)->cells(), false));
    }

    public function testATopLevelTrackIsRefusedToo(): void
    {
        $client = static::createClient();
        $this->rider($client, 'toplevel');
        $this->post($client, ['v' => 1, 'lines' => [], 'points' => [[5, 52]]]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testTooManyLinesOrAnotherVersionIsRefused(): void
    {
        $client = static::createClient();
        $this->rider($client, 'many');
        $this->post($client, ['v' => 1, 'lines' => array_fill(0, 2001, self::line())]);
        self::assertResponseStatusCodeSame(422);

        $this->post($client, ['v' => 2, 'lines' => [self::line()]]);
        self::assertResponseStatusCodeSame(422);

        $this->post($client, 'not json');
        self::assertResponseStatusCodeSame(400);
    }

    public function testAValidLineIsAddedAndAnImplausibleOneDropped(): void
    {
        $client = static::createClient();
        $this->rider($client, 'valid');
        $body = $this->post($client, ['v' => 1, 'lines' => [self::line(), self::line(['timeS' => 0])]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'added' => 1, 'duplicate' => 0, 'dropped' => 1], $body);
        $cells = iterator_to_array(static::getContainer()->get(TrafficStore::class)->cells(), false);
        self::assertCount(1, $cells);
        self::assertCount(1, static::getContainer()->get(TrafficStore::class)->riders());
    }

    public function testTheSamePayloadAgainAddsNothing(): void
    {
        $client = static::createClient();
        $this->rider($client, 'again');
        $payload = ['v' => 1, 'lines' => [self::line()]];
        $this->post($client, $payload);
        $body = $this->post($client, $payload);

        self::assertSame(['ok' => true, 'added' => 0, 'duplicate' => 1, 'dropped' => 0], $body);
        $cells = iterator_to_array(static::getContainer()->get(TrafficStore::class)->cells(), false);
        self::assertSame(1, $cells[0]['contributions']);
    }

    public function testASecondAccountSendingTheSameRideAddsNothing(): void
    {
        $client = static::createClient();
        $payload = ['v' => 1, 'lines' => [self::line()]];
        $this->rider($client, 'first');
        $this->post($client, $payload);
        $this->rider($client, 'second');
        $body = $this->post($client, $payload);

        self::assertSame(1, $body['duplicate']);
        self::assertCount(1, static::getContainer()->get(TrafficStore::class)->riders(), 'the second account counts as no rider');
    }

    public function testTwoLinesSharingANewBlockInOneRequestAreBothAdded(): void
    {
        $client = static::createClient();
        $this->rider($client, 'shared');
        $block = bin2hex(random_bytes(32));
        $body = $this->post($client, ['v' => 1, 'lines' => [
            self::line(['blocks' => [$block]]),
            self::line(['way' => 99, 'blocks' => [$block]]),
        ]]);

        self::assertSame(2, $body['added']);
    }

    public function testAnExhaustedBudgetIs429(): void
    {
        $client = static::createClient();
        $user = $this->rider($client, 'budget');
        $limiter = static::getContainer()->get('limiter.traffic_submit')->create('user-'.(string) $user->getId());
        $limiter->consume($limiter->consume(0)->getRemainingTokens());

        $body = $this->post($client, ['v' => 1, 'lines' => [self::line()]]);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $body['error']);
    }
}
