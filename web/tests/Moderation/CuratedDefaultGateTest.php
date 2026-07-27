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
 * The Regions desk and its GATE
 * (2026-07-27-map-view-mode-default-design.md §4). The desk hides the toggle on
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

    private function seedRegion(string $slug): Region
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = (new Region())->setSlug($slug)->setName(ucfirst($slug))->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}');
        $em->persist($region);
        $em->flush();

        return $region;
    }

    private function addCuratedItems(int $regionId, int $n): void
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);
        for ($i = 0; $i < $n; ++$i) {
            $db->executeStatement(
                "INSERT INTO item (letter, name, source, source_ref, state, country_code, attributes, region_id, geom, created_at, updated_at)
                 VALUES ('B', 'pick', 'manual', :ref, 'verified', 'BE', '{\"cur\": true}'::jsonb, :r,
                         ST_SetSRID(ST_MakePoint(5.0, 50.0), 4326), NOW(), NOW())",
                ['ref' => 'gate:'.$regionId.':'.$i, 'r' => $regionId],
            );
        }
    }

    private function curatedDefault(int $regionId): bool
    {
        /** @var Connection $db */
        $db = static::getContainer()->get(Connection::class);

        return (bool) $db->fetchOne('SELECT curated_default FROM region WHERE id = :id', ['id' => $regionId]);
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
        self::assertStringContainsString('Needs 25 curated picks first', $html);
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
            '_token' => $token, 'region' => (int) $unready->getId(), 'enable' => '1',
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
            '_token' => $token, 'region' => (int) $region->getId(), 'enable' => '1',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertTrue($this->curatedDefault((int) $region->getId()));

        // Disabling is never gated: the threshold exists to stop premature
        // ENABLING, not to trap a region in a mode its content no longer supports.
        $client->request('POST', '/moderate/regions/curated-default', [
            '_token' => $token, 'region' => (int) $region->getId(), 'enable' => '0',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertFalse($this->curatedDefault((int) $region->getId()));
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
            '_token' => $token, 'region' => (int) $theirs->getId(), 'enable' => '1',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->curatedDefault((int) $theirs->getId()));
    }
}
