<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RouteModerateTest extends WebTestCase
{
    private function curator(): User
    {
        $c = static::getContainer();
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $em = $c->get(EntityManagerInterface::class);
        $u = (new User())->setEmail('curator@test.test')->setDisplayName('C');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $u->setPassword($hasher->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function submittedRoute(EntityManagerInterface $em): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Desk proposal · Condroz')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(24000)->setState(ItemState::Submitted)
            ->setSource(ItemSource::User)->setSourceRef('user:desk-1')->setRegionId(1)->setProposedBy(9);
        $em->persist($r);
        $em->flush();

        return $r;
    }

    public function testRiderIsForbiddenFromTheRoutesDesk(): void
    {
        $client = static::createClient();
        $rider = (new User())->setEmail('rider@test.test')->setDisplayName('R');
        $rider->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $rider->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($rider, 'password1234'));
        static::getContainer()->get(EntityManagerInterface::class)->persist($rider);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($rider);
        $client->request('GET', '/moderate/routes');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCuratorSeesPendingProposalAndApprovesIt(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);

        $client->loginUser($this->curator());
        $crawler = $client->request('GET', '/moderate/routes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Desk proposal · Condroz');

        $form = $crawler->selectButton('Record decision')->form([
            'route_decision[route_id]' => (string) $route->getId(),
            'route_decision[decision]' => 'approve',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em->clear();
        self::assertSame(ItemState::Unverified, $em->find(RecommendedRoute::class, $route->getId())->getState());
    }
}
