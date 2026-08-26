<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Region;
use App\Entity\User;
use App\Moderation\Entity\ModeratorArea;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The Regions desk and its GATE.
 * The desk hides the toggle on
 * a region that has not reached the threshold — but a POST is a POST, so the
 * controller must re-check rather than trust the rendered form. Jurisdiction is
 * enforced the same way, matching every other moderation write.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class CuratedDefaultGateTest extends WebTestCase
{
    private function curator(string $email, bool $admin = false): User
    {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName(strstr($email, '@', true) ?: $email);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($admin ? ['ROLE_ADMIN'] : ['ROLE_CURATOR']);
        $user->setPassword($hasher->hashPassword($user, 'hunter2secure!'));
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function seedRegion(string $slug, string $countryCode = 'BE'): Region
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = (new Region())->setSlug($slug)->setName(ucfirst($slug))->setCountryCode($countryCode)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}');
        $em->persist($region);
        $em->flush();

        return $region;
    }

    /**
     * Seeds $n curated picks SPREAD over the climb/stay/scenic blocks, because
     * readiness is breadth as well as depth: $n items all on one letter no
     * longer unlock the gate.
     */
    private function addCuratedItems(int $regionId, int $n): void
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        $letters = ['N', 'O', 'P'];
        for ($i = 0; $i < $n; ++$i) {
            $db->executeStatement(
                "INSERT INTO item (letter, name, source, source_ref, state, country_code, attributes, region_id, geom, created_at, updated_at)
                 VALUES (:l, 'pick', 'manual', :ref, 'verified', 'BE', '{\"cur\": true}'::jsonb, :r,
                         ST_SetSRID(ST_MakePoint(5.0, 50.0), 4326), NOW(), NOW())",
                ['l' => $letters[$i % 3], 'ref' => 'gate:'.$regionId.':'.$i, 'r' => $regionId],
            );
        }
    }

    /** $n picks all on ONE letter — meets a bare total, fails breadth. */
    private function addLopsidedItems(int $regionId, int $n): void
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        for ($i = 0; $i < $n; ++$i) {
            $db->executeStatement(
                "INSERT INTO item (letter, name, source, source_ref, state, country_code, attributes, region_id, geom, created_at, updated_at)
                 VALUES ('I', 'view', 'manual', :ref, 'verified', 'BE', '{\"cur\": true}'::jsonb, :r,
                         ST_SetSRID(ST_MakePoint(5.0, 50.0), 4326), NOW(), NOW())",
                ['ref' => 'lop:'.$regionId.':'.$i, 'r' => $regionId],
            );
        }
    }

    private function curatedDefault(int $regionId): bool
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        return 'curated' === $db->fetchOne('SELECT default_map_mode FROM region WHERE id = :id', ['id' => $regionId]);
    }

    /** The desk renders the token; read it back the way the browser would. */
    private function tokenFromDesk(KernelBrowser $client): string
    {
        $client->request('GET', '/moderate/regions');
        self::assertResponseIsSuccessful();
        self::assertSame(1, preg_match(
            '/name="_token" value="([^"]+)"/',
            (string) $client->getResponse()->getContent(),
            $m,
        ), 'at least one region is unlocked, so the desk rendered a form');

        return $m[1];
    }

    public function testDeskShowsTheCountAndLocksAnUnreadyRegion(): void
    {
        $client = static::createClient();
        $region = $this->seedRegion('gate-unready');
        $this->addCuratedItems((int) $region->getId(), 2);
        $curator = $this->curator('gate-desk@example.com');
        $client->loginUser($curator, 'main');

        $client->request('GET', '/moderate/regions');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        // The count is surfaced whether or not the gate is open — a curator has
        // to be able to see how far off a region is, not just that it is locked.
        self::assertStringContainsString('2 of 25 curated', $html);
        self::assertStringContainsString('Needs 25 picks over 3 blocks of 5', $html);
        // The per-block breakdown, so a moderator sees WHICH kind is short.
        self::assertStringContainsString('23 more curated picks needed', $html);
        self::assertStringContainsString('3 more block(s) need at least 5', $html);
    }

    /**
     * The MIDDLE rung has its own bar (owner 2026-08-12). A region with a
     * handful of vouched-for places may open in Confirmed long before it has a
     * best-of — and one with none may not, because "opens in Confirmed" would
     * then promise a screen with nothing on it, which is the trap the whole
     * gate exists for, one rung lower.
     */
    public function testTheConfirmedRungHasItsOwnGate(): void
    {
        $client = static::createClient();
        $thin = $this->seedRegion('gate-thin');
        $this->addConfirmedItems((int) $thin->getId(), 2);
        $lively = $this->seedRegion('gate-lively');
        $this->addConfirmedItems((int) $lively->getId(), 10);
        $curator = $this->curator('gate-confirmed@example.com', admin: true);
        $client->loginUser($curator, 'main');

        $token = $this->tokenFromDesk($client);

        $client->request('POST', '/moderate/regions/curated-default', [
            '_token' => $token, 'region' => (int) $thin->getId(), 'mode' => 'confirmed',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects();
        self::assertSame('everything', $this->defaultMode((int) $thin->getId()),
            'two confirmed places is not a Confirmed map');

        $client->request('POST', '/moderate/regions/curated-default', [
            '_token' => $token, 'region' => (int) $lively->getId(), 'mode' => 'confirmed',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects();
        self::assertSame('confirmed', $this->defaultMode((int) $lively->getId()));

        // And down again, whatever the counts say: the gate stops premature
        // promises, it never traps a region in a mode.
        $client->request('POST', '/moderate/regions/curated-default', [
            '_token' => $token, 'region' => (int) $lively->getId(), 'mode' => 'everything',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertSame('everything', $this->defaultMode((int) $lively->getId()));
    }

    /** $n places somebody has vouched for — verified, the map's own test. */
    private function addConfirmedItems(int $regionId, int $n): void
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        for ($i = 0; $i < $n; ++$i) {
            $db->executeStatement(
                "INSERT INTO item (letter, name, source, source_ref, state, country_code, attributes, region_id, geom, created_at, updated_at)
                 VALUES ('C', 'tap', 'manual', :ref, 'verified', 'BE', '{}'::jsonb, :r,
                         ST_SetSRID(ST_MakePoint(5.0, 50.0), 4326), NOW(), NOW())",
                ['ref' => 'conf:'.$regionId.':'.$i, 'r' => $regionId],
            );
        }
    }

    private function defaultMode(int $regionId): string
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        return (string) $db->fetchOne('SELECT default_map_mode FROM region WHERE id = :id', ['id' => $regionId]);
    }

    public function testEnablingAnUnreadyRegionIsRefusedEvenWhenPostedDirectly(): void
    {
        $client = static::createClient();
        $ready = $this->seedRegion('gate-ready');
        $this->addCuratedItems((int) $ready->getId(), 25);   // unlocks, so the desk renders a form
        $unready = $this->seedRegion('gate-blocked');
        $this->addCuratedItems((int) $unready->getId(), 3);
        $curator = $this->curator('gate-post@example.com', admin: true);
        $client->loginUser($curator, 'main');

        $token = $this->tokenFromDesk($client);
        $client->request('POST', '/moderate/regions/curated-default', [
            '_token' => $token, 'region' => (int) $unready->getId(), 'mode' => 'curated',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects();
        self::assertFalse($this->curatedDefault((int) $unready->getId()),
            'the gate is enforced in the controller, not just hidden in the template');
    }

    public function testAReadyRegionCanBeFlippedAndFlippedBack(): void
    {
        $client = static::createClient();
        $region = $this->seedRegion('gate-flip');
        $this->addCuratedItems((int) $region->getId(), 30);
        $curator = $this->curator('gate-flip@example.com', admin: true);
        $client->loginUser($curator, 'main');

        $token = $this->tokenFromDesk($client);
        $client->request('POST', '/moderate/regions/curated-default', [
            '_token' => $token, 'region' => (int) $region->getId(), 'mode' => 'curated',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertTrue($this->curatedDefault((int) $region->getId()));

        // Disabling is never gated: the threshold exists to stop premature
        // ENABLING, not to trap a region in a mode its content no longer supports.
        $client->request('POST', '/moderate/regions/curated-default', [
            '_token' => $token, 'region' => (int) $region->getId(), 'mode' => 'everything',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertFalse($this->curatedDefault((int) $region->getId()));
    }

    /**
     * The owner's question, end to end: 25 scenic views and nothing else meets
     * the total but must NOT unlock Curated, because a rider opening that
     * region finds nowhere to sleep and no routes.
     */
    public function testATotalCarriedByOneLayerDoesNotUnlockTheGate(): void
    {
        $client = static::createClient();
        $ready = $this->seedRegion('gate-spread');
        $this->addCuratedItems((int) $ready->getId(), 30);      // spread -> unlocks, renders a form
        $lopsided = $this->seedRegion('gate-lopsided');
        $this->addLopsidedItems((int) $lopsided->getId(), 30);  // all scenic views
        $curator = $this->curator('gate-lopsided@example.com', admin: true);
        $client->loginUser($curator, 'main');

        $token = $this->tokenFromDesk($client);
        $client->request('POST', '/moderate/regions/curated-default', [
            '_token' => $token, 'region' => (int) $lopsided->getId(), 'mode' => 'curated',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects();
        self::assertFalse($this->curatedDefault((int) $lopsided->getId()),
            '30 items on one layer meets the total but not the breadth requirement');
    }

    public function testACuratorCannotFlipARegionOutsideTheirArea(): void
    {
        $client = static::createClient();
        $mine = $this->seedRegion('gate-mine');
        $this->addCuratedItems((int) $mine->getId(), 30);
        $theirs = $this->seedRegion('gate-theirs');
        $this->addCuratedItems((int) $theirs->getId(), 30);

        $curator = $this->curator('gate-scope@example.com');
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new ModeratorArea((int) $curator->getId(), (int) $mine->getId(), null));
        $em->flush();
        $client->loginUser($curator, 'main');

        // The desk only lists their own region.
        $client->request('GET', '/moderate/regions');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Gate-mine', $html);
        self::assertStringNotContainsString('Gate-theirs', $html);

        $token = $this->tokenFromDesk($client);
        $client->request('POST', '/moderate/regions/curated-default', [
            '_token' => $token, 'region' => (int) $theirs->getId(), 'mode' => 'curated',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->curatedDefault((int) $theirs->getId()));
    }

    /**
     * The desk is NOT paged: every onboarded country is on the one page.
     *
     * It used to page by region, which broke the moment the desk grouped by
     * country: 25 regions was four countries with fifteen hidden, and Japan's
     * 47 prefectures spanned two pages. Paging by country fixed the splitting
     * and was still wrong shape for a settings desk (owner, 2026-08-14:
     * "Pagination for Regions just remove it, it makes no sense").
     *
     * This test is the guard against it coming back: seed more countries than
     * any plausible page size and assert every one of them renders at once.
     * The country FILTER is a different thing and still works.
     */
    public function testTheDeskShowsEveryCountryWithoutPaging(): void
    {
        $client = static::createClient();
        $countries = ['AR', 'AT', 'AU', 'BG', 'BR', 'CN', 'CZ', 'DK', 'EE', 'FI',
            'GR', 'HR', 'HU', 'IE', 'IN', 'IS', 'KE', 'LT', 'LV', 'MA',
            'MX', 'NO', 'PE', 'PL', 'PT', 'RO', 'SE'];
        foreach ($countries as $i => $cc) {
            $this->seedRegion(sprintf('paged-a-%02d', $i), $cc);
            $this->seedRegion(sprintf('paged-b-%02d', $i), $cc);
        }
        $client->loginUser($this->curator('gate-paging@example.com'), 'main');

        $client->request('GET', '/moderate/regions');
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();

        self::assertGreaterThanOrEqual(
            \count($countries),
            substr_count($body, '<details class="rg-country"'),
            'every seeded country is on the one page',
        );
        self::assertGreaterThanOrEqual(
            \count($countries) * 2,
            substr_count($body, 'class="rg-item"'),
            'and so is every one of their regions',
        );
        self::assertStringNotContainsString('page=2', $body, 'nothing offers a second page');

        // The country filter is a separate thing and still narrows.
        $client->request('GET', '/moderate/regions?country=AR');
        self::assertResponseIsSuccessful();
        $filtered = (string) $client->getResponse()->getContent();
        self::assertSame(1, substr_count($filtered, '<details class="rg-country"'), 'the filter narrows to one country');
        self::assertSame(2, substr_count($filtered, 'class="rg-item"'), 'showing only its regions');
    }
}
