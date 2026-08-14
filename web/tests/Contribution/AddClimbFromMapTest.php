<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * "Add a climb here": the map hands the wizard the view it was showing
 * (map-and-search.md §8.1).
 *
 * Two halves, and the second is the one worth pinning: the coordinates arrive
 * from the URL bar as readily as from our own link, so a junk value has to
 * degrade to the wizard's default centre rather than to a broken map.
 */
final class AddClimbFromMapTest extends WebTestCase
{
    private function rider(EntityManagerInterface $em, string $email): User
    {
        $u = (new User())->setEmail($email)->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    public function testTheRailOffersAddAClimbHereToASignedInRider(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($this->rider($em, 'climb-rail@test.test'));

        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        // panels.js rewrites this href on every move; the served value is the
        // bare route, so a rider with JS off still lands on the wizard.
        self::assertSelectorExists('a#addClimbHere[href="/add-climb"]');
    }

    public function testTheRailOffersNothingToAnAnonymousVisitor(): void
    {
        $client = static::createClient();
        $client->request('GET', '/map');
        self::assertResponseIsSuccessful();
        // /add-climb is ROLE_USER: a link that lands on a login wall is worse
        // than no link, so the whole block stays out of the anonymous page.
        self::assertSelectorNotExists('a#addClimbHere');
    }

    public function testAValidViewReachesTheWizard(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($this->rider($em, 'climb-view@test.test'));

        $client->request('GET', '/add-climb?lat=50.49&lng=5.74&z=14.5');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('CC_CLIMB_VIEW', $html);
        self::assertStringContainsString('"lat":50.49', $html);
        self::assertStringContainsString('"lng":5.74', $html);
        self::assertStringContainsString('"zoom":14.5', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function junkViews(): iterable
    {
        yield 'no coordinates at all' => [''];
        yield 'not a number' => ['?lat=north&lng=5.74'];
        yield 'latitude past the pole' => ['?lat=91&lng=5.74'];
        yield 'longitude past the meridian' => ['?lat=50.49&lng=181'];
        yield 'longitude only' => ['?lng=5.74'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('junkViews')]
    public function testJunkFallsBackToTheWizardsOwnCentre(string $query): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($this->rider($em, 'climb-junk'.md5($query).'@test.test'));

        $client->request('GET', '/add-climb'.$query);
        self::assertResponseIsSuccessful();
        // No global emitted at all, so add-climb.js keeps its own default.
        self::assertStringNotContainsString('CC_CLIMB_VIEW', (string) $client->getResponse()->getContent());
    }

    public function testZoomIsClampedRatherThanThrowingTheCoordinatesAway(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $client->loginUser($this->rider($em, 'climb-zoom@test.test'));

        $client->request('GET', '/add-climb?lat=50.49&lng=5.74&z=99');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        // A bad zoom is a bad number in an otherwise good link; the position is
        // the part that matters, so it survives.
        self::assertStringContainsString('"lat":50.49', $html);
        self::assertStringContainsString('"zoom":18', $html);
    }
}
