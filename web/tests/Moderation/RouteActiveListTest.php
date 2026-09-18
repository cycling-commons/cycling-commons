<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Region;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Moderation\Entity\ModeratorArea;
use App\Moderation\ModerationScope;
use App\Moderation\RouteQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The Routes desk's active-route list (docs/specs/route-domain.md §5.2): every
 * live route in one region, so a curator reaches one without the map. Curator
 * surface only, and scoped to the curator's own areas.
 */
final class RouteActiveListTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private RouteQueue $queue;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->queue = static::getContainer()->get(RouteQueue::class);
    }

    /** The desk's region picker joins `region`, so a listed route needs its row. */
    private function region(string $name): int
    {
        $r = (new Region())->setSlug('ral-'.bin2hex(random_bytes(4)))->setName($name)->setCountryCode('BE');
        $this->em->persist($r);
        $this->em->flush();

        return (int) $r->getId();
    }

    private function curator(): User
    {
        $u = (new User())->setEmail('ral-'.bin2hex(random_bytes(4)).'@test.test')->setDisplayName('C');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    /** @param array<string, mixed> $attributes */
    private function route(ItemState $state, ?int $regionId, string $name, array $attributes = []): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName($name)
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(21000)->setAscentM(410)->setState($state)->setSource(ItemSource::User)
            ->setSourceRef('user:'.bin2hex(random_bytes(8)))->setRegionId($regionId)
            ->setAttributes($attributes);
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    /** "Active" is exactly what the cap counts: unverified and verified, no more. */
    public function testOnlyServedStatesAreActive(): void
    {
        $tag = bin2hex(random_bytes(3));
        $rid = $this->region('Active states');
        $this->route(ItemState::Unverified, $rid, 'ral-a-'.$tag);
        $this->route(ItemState::Verified, $rid, 'ral-b-'.$tag);
        $this->route(ItemState::Retired, $rid, 'ral-retired-'.$tag);
        $this->route(ItemState::Submitted, $rid, 'ral-submitted-'.$tag);
        $this->route(ItemState::Rejected, $rid, 'ral-rejected-'.$tag);

        $names = array_column($this->queue->activeInRegion(ModerationScope::global(), $rid), 'name');
        self::assertContains('ral-a-'.$tag, $names);
        self::assertContains('ral-b-'.$tag, $names);
        self::assertNotContains('ral-retired-'.$tag, $names);
        self::assertNotContains('ral-submitted-'.$tag, $names);
        self::assertNotContains('ral-rejected-'.$tag, $names);
    }

    /**
     * The list and the cap counter can never disagree: the counter is the
     * list's length, and both read the same definition.
     */
    public function testTheListLengthIsTheCapCounter(): void
    {
        $moderation = static::getContainer()->get(\App\Moderation\RouteModerationService::class);
        $rid = $this->region('Counter');
        $this->route(ItemState::Unverified, $rid, 'ral-count-'.bin2hex(random_bytes(3)));
        $this->route(ItemState::Verified, $rid, 'ral-count2-'.bin2hex(random_bytes(3)));
        $this->route(ItemState::Retired, $rid, 'ral-count3-'.bin2hex(random_bytes(3)));

        self::assertCount(
            $moderation->activeCountForRegion($rid),
            $this->queue->activeInRegion(ModerationScope::global(), $rid),
        );
    }

    /** A region with no live routes is simply empty, not an error. */
    public function testARegionWithNothingLiveIsEmpty(): void
    {
        $rid = $this->region('Nothing live');
        $this->route(ItemState::Retired, $rid, 'ral-only-retired-'.bin2hex(random_bytes(3)));

        self::assertSame([], $this->queue->activeInRegion(ModerationScope::global(), $rid));
    }

    /** A curator sees only the regions their areas cover. */
    public function testTheListIsScopedToTheCuratorsAreas(): void
    {
        $tag = bin2hex(random_bytes(3));
        $mineId = $this->region('Mine');
        $theirsId = $this->region('Theirs');
        $this->route(ItemState::Unverified, $mineId, 'ral-mine-'.$tag);
        $this->route(ItemState::Unverified, $theirsId, 'ral-theirs-'.$tag);

        $mine = ModerationScope::limited([$mineId], []);
        self::assertContains('ral-mine-'.$tag, array_column($this->queue->activeInRegion($mine, $mineId), 'name'));
        self::assertSame([], $this->queue->activeInRegion($mine, $theirsId), 'a region outside the areas yields nothing');

        $regionIds = array_column($this->queue->activeRegions($mine), 'id');
        self::assertContains($mineId, $regionIds);
        self::assertNotContains($theirsId, $regionIds);
    }

    /** Rows name the registry fields nobody has filled in yet. */
    public function testRowsNameTheUnsetFields(): void
    {
        $tag = bin2hex(random_bytes(3));
        $rid = $this->region('Gaps');
        $this->route(ItemState::Unverified, $rid, 'ral-gaps-'.$tag, ['difficulty' => ['score' => 3, 'label' => 'Challenging']]);

        $row = null;
        foreach ($this->queue->activeInRegion(ModerationScope::global(), $rid) as $r) {
            if ($r['name'] === 'ral-gaps-'.$tag) {
                $row = $r;
            }
        }
        self::assertNotNull($row);
        $missing = array_column($row['missing'], 'key');
        self::assertNotContains('difficulty', $missing, 'a filled field is not missing');
        foreach (['season', 'dominantSurface', 'note', 'bikeTypes', 'gradientLimited', 'bestDirection'] as $field) {
            self::assertContains($field, $missing);
        }
        // Each carries the label every other surface shows the field by.
        self::assertSame('propose_route.season_label', $row['missing'][0]['label']);
    }

    /** A value the vocabulary no longer knows reads as unset, as the form shows it. */
    public function testAnOutOfVocabularyValueCountsAsUnset(): void
    {
        $tag = bin2hex(random_bytes(3));
        $rid = $this->region('Junk');
        $this->route(ItemState::Unverified, $rid, 'ral-junk-'.$tag, ['gradientLimited' => 'BOGUS']);

        foreach ($this->queue->activeInRegion(ModerationScope::global(), $rid) as $r) {
            if ($r['name'] === 'ral-junk-'.$tag) {
                self::assertContains('gradientLimited', array_column($r['missing'], 'key'));

                return;
            }
        }
        self::fail('route not listed');
    }

    /** A lookup list is ordered by name, not by age. */
    public function testRowsAreOrderedByName(): void
    {
        $tag = bin2hex(random_bytes(3));
        $rid = $this->region('Ordering');
        $this->route(ItemState::Unverified, $rid, 'ral-zz-'.$tag);
        $this->route(ItemState::Unverified, $rid, 'ral-aa-'.$tag);

        $names = array_values(array_filter(
            array_column($this->queue->activeInRegion(ModerationScope::global(), $rid), 'name'),
            static fn (string $n): bool => str_contains($n, $tag),
        ));
        self::assertSame(['ral-aa-'.$tag, 'ral-zz-'.$tag], $names);
    }

    /** The desk shows the section, and each row opens that route's own form. */
    public function testTheDeskListsTheRegionsActiveRoutes(): void
    {
        $tag = bin2hex(random_bytes(3));
        $rid = $this->region('Desk');
        $route = $this->route(ItemState::Unverified, $rid, 'ral-desk-'.$tag);

        $this->client->loginUser($this->curator());
        $crawler = $this->client->request('GET', '/moderate/routes?region='.$rid);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Active routes', $crawler->text());
        self::assertStringContainsString('ral-desk-'.$tag, $crawler->text());
        self::assertSame(
            1,
            $crawler->filter('.active-list a[href$="/moderate/routes/'.$route->getId().'"]')->count(),
            'the row opens the route on the desk',
        );
    }

    /** An in-scope region with nothing live names itself, rather than falling back to "pick a region". */
    public function testAnEmptyRegionSaysWhichRegionIsEmpty(): void
    {
        $rid = $this->region('Quiet county');

        $this->client->loginUser($this->curator());
        $crawler = $this->client->request('GET', '/moderate/routes?region='.$rid);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No routes are live in Quiet county yet.', $crawler->text());
        self::assertSame(0, $crawler->filter('.active-list .q-item--active')->count());
    }

    /** A region outside the curator's areas is neither named nor listed. */
    public function testAnOutOfScopeRegionIsNotNamed(): void
    {
        $theirs = $this->region('Somebody elses county');
        $this->route(ItemState::Unverified, $theirs, 'ral-theirs-'.bin2hex(random_bytes(3)));

        self::assertNull($this->queue->regionInScope(ModerationScope::limited([$theirs + 1000], []), $theirs));
        self::assertNotNull($this->queue->regionInScope(ModerationScope::global(), $theirs));
    }

    /**
     * The desk's region filter offers a curator every region their areas
     * cover, even one where nothing waits, and never a region outside them.
     */
    public function testTheFilterOffersEveryRegionInTheCuratorsAreas(): void
    {
        $quietId = $this->region('Quiet');
        $theirsId = $this->region('Theirs');
        $this->route(ItemState::Submitted, $theirsId, 'ral-waiting-'.bin2hex(random_bytes(3)));

        $ids = array_column($this->queue->regions(ModerationScope::limited([$quietId], [])), 'id');
        self::assertContains($quietId, $ids, 'a region of theirs with nothing waiting is still offered');
        self::assertNotContains($theirsId, $ids, 'a region outside the areas is never offered');
    }

    /** A global curator is offered only regions where the desk has something to show. */
    public function testAGlobalCuratorIsOfferedOnlyRegionsWithRoutes(): void
    {
        $emptyId = $this->region('Empty');
        $liveId = $this->region('Live');
        $this->route(ItemState::Unverified, $liveId, 'ral-live-'.bin2hex(random_bytes(3)));

        $ids = array_column($this->queue->regions(ModerationScope::global()), 'id');
        self::assertContains($liveId, $ids);
        self::assertNotContains($emptyId, $ids);
    }

    /**
     * A curator with areas and no region applied sees the live routes of every
     * region of theirs at once, grouped per region, and the quiet regions named
     * in one line. They are never asked to pick, and never shown one region
     * the filter above is not showing.
     */
    public function testACuratorWithAreasSeesEveryRegionsLiveRoutes(): void
    {
        $tag = bin2hex(random_bytes(3));
        $liveId = $this->region('Live '.$tag);
        $quietId = $this->region('Quiet '.$tag);
        $this->route(ItemState::Unverified, $liveId, 'ral-grouped-'.$tag);
        $curator = $this->curator();
        $this->em->persist(new ModeratorArea((int) $curator->getId(), $liveId, null));
        $this->em->persist(new ModeratorArea((int) $curator->getId(), $quietId, null));
        $this->em->flush();

        $this->client->loginUser($curator);
        $crawler = $this->client->request('GET', '/moderate/routes');
        self::assertResponseIsSuccessful();
        $section = $crawler->filter('.active-list')->text();
        self::assertStringContainsString('ral-grouped-'.$tag, $section);
        $page = $crawler->filter('body')->text();
        self::assertStringContainsString('Live routes in Live '.$tag, $page);
        self::assertStringContainsString('No routes are live yet in Quiet '.$tag, $page);
        self::assertStringNotContainsString('Pick a region', $page);
    }

    /** A rider never sees it: the desk is curator-only, list and all. */
    public function testARiderCannotReachTheDesk(): void
    {
        $u = (new User())->setEmail('ral-rider-'.bin2hex(random_bytes(4)).'@test.test');
        $u->setPassword('x');
        $this->em->persist($u);
        $this->em->flush();

        $this->client->loginUser($u);
        $this->client->request('GET', '/moderate/routes');
        self::assertResponseStatusCodeSame(403);
    }
}
