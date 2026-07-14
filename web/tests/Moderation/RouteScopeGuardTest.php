<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Region;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Moderation\Entity\ModeratorArea;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * moderator-areas spec 2026-07-14, task 4: hard scope guards on the routes
 * desk. A curator confined to region A must not be able to see, view, decide
 * on, or trash a route proposal that lives in region B — even by posting the
 * id directly, bypassing whatever the queue happens to render.
 *
 * Copied helper shapes from RouteModerateTest (curator/submittedRoute) and
 * ModerateScopeGuardTest (seedRegion/assignRegion) — see those files for why
 * ROLE_CURATOR needs a totpSecret here and why proposedBy must be a real
 * persisted user (user_message.user_id FK).
 */
final class RouteScopeGuardTest extends WebTestCase
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    private function curator(): User
    {
        $c = static::getContainer();
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $em = $c->get(EntityManagerInterface::class);
        $u = (new User())->setEmail('rsg-curator-'.uniqid('', true).'@test.test')->setDisplayName('C');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $u->setPassword($hasher->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function proposer(EntityManagerInterface $em): User
    {
        $u = (new User())->setEmail('rsg-proposer-'.uniqid('', true).'@test.test');
        $u->setPassword('x');
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function seedRegion(EntityManagerInterface $em, string $slug, string $name, string $country): Region
    {
        $region = (new Region())->setSlug($slug)->setName($name)->setCountryCode($country)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}');
        $em->persist($region);
        $em->flush();

        return $region;
    }

    private function assignRegion(User $curator, int $regionId): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new ModeratorArea((int) $curator->getId(), $regionId, null));
        $em->flush();
    }

    private function submittedRoute(EntityManagerInterface $em, string $name, ?int $regionId): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName($name)
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(24000)->setState(ItemState::Submitted)
            ->setSource(ItemSource::User)->setSourceRef('user:rsg-'.uniqid('', true))
            ->setRegionId($regionId)->setProposedBy((int) $this->proposer($em)->getId());
        $em->persist($r);
        $em->flush();

        return $r;
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    public function testQueueHidesOutOfScopeRoute(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $regionA = $this->seedRegion($em, 'rsg-queue-a', 'RSG Queue A', 'BE');
        $regionB = $this->seedRegion($em, 'rsg-queue-b', 'RSG Queue B', 'NL');
        $this->submittedRoute($em, 'Proposal in region A', (int) $regionA->getId());
        $this->submittedRoute($em, 'Proposal in region B', (int) $regionB->getId());

        $curator = $this->curator();
        $this->assignRegion($curator, (int) $regionA->getId());
        $client->loginUser($curator);

        $client->request('GET', '/moderate/routes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Proposal in region A');
        self::assertSelectorTextNotContains('body', 'Proposal in region B');
    }

    public function testDetailOfOutOfScopeRouteIs403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $regionA = $this->seedRegion($em, 'rsg-detail-a', 'RSG Detail A', 'BE');
        $regionB = $this->seedRegion($em, 'rsg-detail-b', 'RSG Detail B', 'NL');
        $routeB = $this->submittedRoute($em, 'Detail out-of-scope', (int) $regionB->getId());

        $curator = $this->curator();
        $this->assignRegion($curator, (int) $regionA->getId());
        $client->loginUser($curator);

        $client->request('GET', '/moderate/routes/'.$routeB->getId());
        self::assertResponseStatusCodeSame(403);
    }

    public function testDecideOnOutOfScopeRouteIs403AndLeavesItSubmitted(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $regionA = $this->seedRegion($em, 'rsg-decide-a', 'RSG Decide A', 'BE');
        $regionB = $this->seedRegion($em, 'rsg-decide-b', 'RSG Decide B', 'NL');
        $inScopeRoute = $this->submittedRoute($em, 'Decide in-scope', (int) $regionA->getId());
        $routeB = $this->submittedRoute($em, 'Decide out-of-scope', (int) $regionB->getId());

        $curator = $this->curator();
        $this->assignRegion($curator, (int) $regionA->getId());
        $client->loginUser($curator);

        // The decision form now lives on the proposal's own (in-scope) detail
        // page rather than the queue list — mint the token there.
        $crawler = $client->request('GET', '/moderate/routes/'.$inScopeRoute->getId());
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('input[name="route_decision[_token]"]')->first()->attr('value');

        $client->request('POST', '/moderate/routes/decide', [
            'route_decision' => [
                'route_id' => (string) $routeB->getId(),
                'decision' => 'approve',
                '_token' => $token,
            ],
        ]);

        self::assertResponseStatusCodeSame(403);

        $em->clear();
        self::assertSame(ItemState::Submitted, $em->find(RecommendedRoute::class, $routeB->getId())->getState());
    }

    public function testTrashOfOutOfScopeProposalIs403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $regionA = $this->seedRegion($em, 'rsg-trash-a', 'RSG Trash A', 'BE');
        $regionB = $this->seedRegion($em, 'rsg-trash-b', 'RSG Trash B', 'NL');
        $routeA = $this->submittedRoute($em, 'Trash in-scope', (int) $regionA->getId());
        $routeB = $this->submittedRoute($em, 'Trash out-of-scope', (int) $regionB->getId());

        $curator = $this->curator();
        $this->assignRegion($curator, (int) $regionA->getId());
        $client->loginUser($curator);

        // The `route-trash` CSRF token id is session-bound, not tied to the
        // row id (TrashTest's proposalTrashToken() pattern), so it can
        // legally be minted from the in-scope route's detail page.
        $crawler = $client->request('GET', '/moderate/routes/'.$routeA->getId());
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('.trash-confirm-proposal input[name="_token"]')->attr('value');

        $client->request('POST', '/moderate/routes/trash', [
            'kind' => 'proposal',
            'id' => (string) $routeB->getId(),
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);

        $em->clear();
        self::assertNotNull($em->find(RecommendedRoute::class, $routeB->getId()));
    }

    public function testInScopeDecideStillSucceeds(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $regionA = $this->seedRegion($em, 'rsg-inscope-a', 'RSG Inscope A', 'BE');
        $routeA = $this->submittedRoute($em, 'In-scope approve', (int) $regionA->getId());

        $curator = $this->curator();
        $this->assignRegion($curator, (int) $regionA->getId());
        $client->loginUser($curator);

        $client->request('GET', '/moderate/routes');
        self::assertResponseIsSuccessful();

        // The decision form moved off the queue list onto the proposal's own
        // detail page (the queue item now just links there for review).
        $crawler = $client->request('GET', '/moderate/routes/'.$routeA->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Record decision')->form([
            'route_decision[route_id]' => (string) $routeA->getId(),
            'route_decision[decision]' => 'approve',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $em->clear();
        self::assertSame(ItemState::Unverified, $em->find(RecommendedRoute::class, $routeA->getId())->getState());
    }
}
