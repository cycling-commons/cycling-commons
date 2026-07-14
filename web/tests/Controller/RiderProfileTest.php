<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

namespace App\Tests\Controller;

use App\Catalog\BikeType;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Public rider profile (spec 2026-07-14): /riders/{uuid} exists only while
 * publicProfile is ON; renders public-appropriate data only; never leaks the
 * email. Anonymous client throughout — the page must not require login.
 *
 * Test isolation: DAMA rollback per test.
 */
final class RiderProfileTest extends WebTestCase
{
    private function makeUser(string $email, string $displayName, bool $public): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPublicProfile($public);
        $user->setBikeTypes([BikeType::Gravel]);
        $user->setPassword($hasher->hashPassword($user, 'securepass12345!'));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function makeRoute(string $name, int $proposerId, ItemState $state): RecommendedRoute
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $route = (new RecommendedRoute())->setName($name)
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState($state)
            ->setSource(ItemSource::User)->setSourceRef('user:profile-'.uniqid())
            ->setRegionId(1)->setProposedBy($proposerId);
        $em->persist($route);
        $em->flush();

        return $route;
    }

    public function testOptedInProfileRendersPublicly(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('public-rider@example.com', 'Public Rider', true);

        $client->request('GET', '/riders/'.$user->getUuid());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Public Rider');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('public-rider@example.com', $html, 'email must never appear');
    }

    public function testHiddenProfileIs404(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('hidden-rider@example.com', 'Hidden Rider', false);

        $client->request('GET', '/riders/'.$user->getUuid());

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownUuidIs404(): void
    {
        $client = static::createClient();

        $client->request('GET', '/riders/'.Uuid::v7());

        self::assertResponseStatusCodeSame(404);
    }

    public function testMalformedUuidIs404(): void
    {
        $client = static::createClient();

        $client->request('GET', '/riders/not-a-uuid');

        self::assertResponseStatusCodeSame(404);
    }

    public function testPendingCountCoversSubmittedAndUnverifiedButNeverNamesThem(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('routes-rider@example.com', 'Routes Rider', true);
        $uid = (int) $user->getId();

        // Submitted = awaiting curator decision; Unverified = curator-approved,
        // awaiting ride-verification. BOTH are "not yet fully verified" and must
        // be counted — but neither name may render on a public surface.
        $this->makeRoute('Secret Submitted Loop', $uid, ItemState::Submitted);
        $this->makeRoute('Secret Unverified Loop', $uid, ItemState::Unverified);
        $verified = $this->makeRoute('Vetted Condroz Classic', $uid, ItemState::Verified);

        $client->request('GET', '/riders/'.$user->getUuid());

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('2 proposed routes awaiting verification', $html, 'pending count must cover Submitted + Unverified');
        self::assertStringNotContainsString('Secret Submitted Loop', $html, 'un-vetted (Submitted) route names must never render');
        self::assertStringNotContainsString('Secret Unverified Loop', $html, 'not-yet-verified route names must never render');
        self::assertStringContainsString('Vetted Condroz Classic', $html, 'verified routes render by name');
        self::assertStringContainsString('?route='.$verified->getId(), $html, 'verified route links to the map');
    }

    public function testLocalizedPathWorks(): void
    {
        $client = static::createClient();
        $user = $this->makeUser('fr-rider@example.com', 'Rider FR', true);

        $client->request('GET', '/fr/riders/'.$user->getUuid());

        self::assertResponseIsSuccessful();
    }
}
