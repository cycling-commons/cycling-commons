<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Entity\User;
use App\Traffic\SafetyStore;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * POST /map/road-safety (docs/specs/traffic-measurements.md §10): a signed-in
 * rider marks how safe one road or several roads felt. Each mark waits, sealed,
 * with no account next to it.
 */
final class SafetyControllerTest extends WebTestCase
{
    private function rider(KernelBrowser $client, string $tag): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail("safety-{$tag}-".bin2hex(random_bytes(3)).'@example.com');
        $user->setPassword('x');
        $user->setDisplayName('Felt rider');
        $user->setEmailVerified(true);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return $user;
    }

    /** @param array<string, mixed>|string $body */
    private function post(KernelBrowser $client, array|string $body, ?string $token = null): array
    {
        $token ??= static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('road-safety')->getValue();
        $client->request('POST', '/map/road-safety', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CC_TOKEN' => $token,
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ], \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $client->getResponse()->getContent(), true) ?? [];
    }

    /** A mark for this quarter, so the rule accepts it whatever day the suite runs. */
    private static function mark(array $over = []): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $over + SafetyMarkTest::mark(['quarters' => [$now->format('Y').'-Q'.(intdiv((int) $now->format('n') - 1, 3) + 1)]]);
    }

    private function waiting(): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM safety_pool');
    }

    public function testASignedOutVisitorCannotMark(): void
    {
        $client = static::createClient();
        $client->request('POST', '/map/road-safety', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(self::mark(), \JSON_THROW_ON_ERROR));

        self::assertContains($client->getResponse()->getStatusCode(), [302, 401]);
        self::assertSame(0, $this->waiting());
    }

    public function testAForeignTokenIsRefused(): void
    {
        $client = static::createClient();
        $this->rider($client, 'token');
        $this->post($client, self::mark(), 'not-the-token');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->waiting());
    }

    public function testAMarkWaitsAndNamesNobody(): void
    {
        $client = static::createClient();
        $user = $this->rider($client, 'valid');
        $body = $this->post($client, self::mark());

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], $body, 'the answer says nothing about the waiting room');
        self::assertSame(1, $this->waiting());
        $db = static::getContainer()->get(Connection::class);
        foreach ($db->fetchAllAssociative('SELECT * FROM safety_pool') as $row) {
            $bytes = implode('', array_map(static fn ($v) => \is_resource($v) ? stream_get_contents($v) : (string) $v, $row));
            self::assertStringNotContainsString((string) $user->getId(), $bytes);
            self::assertStringNotContainsString((string) $user->getEmail(), $bytes);
        }
    }

    public function testATrackShapedMarkIsRefusedWhole(): void
    {
        $client = static::createClient();
        $this->rider($client, 'track');
        $body = $this->post($client, self::mark(['lat' => 52.1, 'lon' => 5.1]));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('track_not_accepted', $body['error']);
        self::assertSame(0, $this->waiting());
    }

    public function testAMalformedMarkIsRefused(): void
    {
        $client = static::createClient();
        $this->rider($client, 'bad');
        $body = $this->post($client, self::mark(['felt' => 'scary']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('invalid', $body['error']);

        $this->post($client, 'not json');
        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, $this->waiting());
    }

    public function testTheFifthMarkOfARoadAndTimeMovesTheBlockToTheCounts(): void
    {
        $client = static::createClient();
        $this->rider($client, 'five');
        foreach (['unsafe', 'unsafe', 'safe', 'unsafe'] as $felt) {
            $this->post($client, self::mark(['felt' => $felt]));
        }
        self::assertSame(4, $this->waiting());

        $this->post($client, self::mark(['felt' => 'unsafe']));

        self::assertSame(0, $this->waiting());
        $totals = iterator_to_array(static::getContainer()->get(SafetyStore::class)->totals(), false);
        self::assertCount(1, $totals);
        self::assertSame([1, 4], [$totals[0]['safe'], $totals[0]['unsafe']]);
    }

    /** Roads picked together (owner 2026-10-19: "CTRL + click"): each waits on its own, and each costs one of the hour's tokens. */
    public function testRoadsPickedTogetherWaitEachAndCostOneTokenEach(): void
    {
        $client = static::createClient();
        $user = $this->rider($client, 'many');
        $body = $this->post($client, self::mark(['roads' => [['way' => 11, 'label' => 'p'], ['way' => 12, 'label' => 'r'], ['way' => 13, 'label' => 'l']]]));

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], $body);
        self::assertSame(3, $this->waiting());
        $limiter = static::getContainer()->get('limiter.road_safety_submit')->create('user-'.(string) $user->getId());
        self::assertSame(200 - 3, $limiter->consume(0)->getRemainingTokens());
    }

    /** Several times for one road (owner 2026-10-19: "make all fields checkboxes"): a mark and a token each. */
    public function testEveryTimeChosenIsAMarkAndAToken(): void
    {
        $client = static::createClient();
        $user = $this->rider($client, 'times');
        $this->post($client, self::mark(['bands' => [1, 3], 'dayTypes' => ['workday', 'weekend']]));

        self::assertResponseIsSuccessful();
        self::assertSame(4, $this->waiting());
        $limiter = static::getContainer()->get('limiter.road_safety_submit')->create('user-'.(string) $user->getId());
        self::assertSame(200 - 4, $limiter->consume(0)->getRemainingTokens());
    }

    public function testAnswersPastTheBudgetAre429(): void
    {
        $client = static::createClient();
        $user = $this->rider($client, 'over');
        $limiter = static::getContainer()->get('limiter.road_safety_submit')->create('user-'.(string) $user->getId());
        $limiter->consume($limiter->consume(0)->getRemainingTokens() - 2);

        $this->post($client, self::mark(['roads' => [['way' => 11, 'label' => 'p'], ['way' => 12, 'label' => 'r'], ['way' => 13, 'label' => 'l']]]));

        self::assertResponseStatusCodeSame(429, 'three roads with two tokens left');
        self::assertSame(0, $this->waiting());
    }

    public function testAnExhaustedBudgetIs429(): void
    {
        $client = static::createClient();
        $user = $this->rider($client, 'budget');
        $limiter = static::getContainer()->get('limiter.road_safety_submit')->create('user-'.(string) $user->getId());
        $limiter->consume($limiter->consume(0)->getRemainingTokens());

        $body = $this->post($client, self::mark());

        self::assertResponseStatusCodeSame(429);
        self::assertSame('rate_limited', $body['error']);
        self::assertSame(0, $this->waiting());
    }
}
