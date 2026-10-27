<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The add form's map opens where the rider looks (moderation-and-contribution.md §1.2, "Where the form's map
 * opens"): the page hands form-start.js the region boxes and the rider's own base location, and only when the link
 * names no spot.
 */
final class FormMapStartTest extends WebTestCase
{
    private function rider(EntityManagerInterface $em, string $email, bool $withBase): User
    {
        $u = (new User())->setEmail($email)->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        if ($withBase) {
            $u->setBaseLocation(52.64, 5.06, 'Hoorn');
        }
        $em->persist($u);
        $em->flush();

        return $u;
    }

    /** @return array{regions: list<array{slug: string, cc: string, view: list<float>}>, base: ?array{lat: float, lng: float}} */
    private function payload(string $html): array
    {
        self::assertSame(1, preg_match('/window\.CC_FORM_START = (\{.*?\});<\/script>/', $html, $m), 'the page carries the start');

        return json_decode($m[1], true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testTheFormGetsTheRidersBaseLocationAndTheRegionBoxes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($this->rider($em, 'form-start-base@test.test', true));

        $client->request('GET', '/improve?type=road-surface&mode=add');
        self::assertResponseIsSuccessful();
        $start = $this->payload((string) $client->getResponse()->getContent());

        self::assertSame(['lat' => 52.64, 'lng' => 5.06], $start['base']);
        foreach ($start['regions'] as $r) {
            self::assertSame(['slug', 'cc', 'view'], array_keys($r), 'a slug, a country and a camera box, no outline');
            self::assertCount(4, $r['view']);
        }
    }

    public function testARiderWithoutABaseLocationGetsNone(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($this->rider($em, 'form-start-none@test.test', false));

        $client->request('GET', '/improve?type=water-food&mode=add');
        self::assertResponseIsSuccessful();
        self::assertNull($this->payload((string) $client->getResponse()->getContent())['base']);
    }

    public function testALinkThatNamesASpotCarriesNoStart(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($this->rider($em, 'form-start-spot@test.test', true));

        $client->request('GET', '/improve?type=road-surface&mode=add&lat=52.656464&lng=5.142613&z=15');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('CC_FORM_START', (string) $client->getResponse()->getContent());
    }
}
