<?php

// SPDX-License-Identifier: AGPL-3.0-only

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

    // Task 4: decisions now write the proposer a message, and user_message.user_id
    // has a real FK to users(id) — proposedBy must be a persisted user, not a
    // fabricated id.
    private function proposer(EntityManagerInterface $em): User
    {
        $u = (new User())->setEmail('proposer-'.uniqid('', true).'@test.test');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function submittedRoute(EntityManagerInterface $em): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Desk proposal · Condroz')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(24000)->setState(ItemState::Submitted)
            ->setSource(ItemSource::User)->setSourceRef('user:desk-1')->setRegionId(1)->setProposedBy((int) $this->proposer($em)->getId());
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
        $client->request('GET', '/moderate/routes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Desk proposal · Condroz');

        // The decision form moved off the queue list onto the proposal's own
        // detail page (the queue item now just links there for review).
        $crawler = $client->request('GET', '/moderate/routes/'.$route->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Record decision')->form([
            'route_decision[route_id]' => (string) $route->getId(),
            'route_decision[decision]' => 'approve',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em->clear();
        self::assertSame(ItemState::Unverified, $em->find(RecommendedRoute::class, $route->getId())->getState());
    }

    private function activeRoute(EntityManagerInterface $em): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Active loop · Condroz')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(18000)->setState(ItemState::Verified)
            ->setSource(ItemSource::User)->setSourceRef('user:desk-active')->setRegionId(1)->setProposedBy((int) $this->proposer($em)->getId());
        $em->persist($r);
        $em->flush();

        return $r;
    }

    public function testCuratorRetiresAnActiveRouteFromTheDetailPage(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->activeRoute($em);

        $client->loginUser($this->curator());
        $crawler = $client->request('GET', '/moderate/routes/'.$route->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Retire this route')->form([
            'route_decision[note]' => 'Superseded by a better loop.',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em->clear();
        self::assertSame(ItemState::Retired, $em->find(RecommendedRoute::class, $route->getId())->getState());
    }

    public function testDetailShowsTrackMetadataAndRegionCapContext(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);

        $client->loginUser($this->curator());
        $client->request('GET', '/moderate/routes/'.$route->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Desk proposal · Condroz');
        self::assertSelectorTextContains('body', '24 km');
        // The region's active-vs-cap context is shown so the curator sees head-room:
        // the real configured cap (30, route.region_active_cap), not the active count itself.
        self::assertSelectorExists('[data-region-cap]');
        self::assertSelectorTextContains('[data-region-cap]', '0 / 30');
    }

    public function testCuratorEditsRouteNoteViaTheDetailForm(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $route = $this->submittedRoute($em);
        $route->setAttributes(['note' => 'Old note']);
        $em->flush();

        $client->loginUser($this->curator());
        $crawler = $client->request('GET', '/moderate/routes/'.$route->getId());
        $form = $crawler->selectButton('Save changes')->form();
        $form['route_edit[note]'] = 'A quiet Condroz loop, resurfaced 2025.';
        $client->submit($form);
        self::assertResponseRedirects();

        $em->clear();
        self::assertSame('A quiet Condroz loop, resurfaced 2025.', $em->find(RecommendedRoute::class, $route->getId())->getAttributes()['note']);
    }
}
