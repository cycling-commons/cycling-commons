<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use App\Service\UserAdminService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A moderator is told when their scope changes.
 *
 * Their areas decide what they can see and act on, so a silent change means
 * finding out by noticing a desk has gone quiet, or that somewhere new has
 * appeared in it with no explanation (owner 2026-08-14). The dashboard row
 * written here is what `MessageMailer` then delivers by email, so one send
 * covers both surfaces.
 *
 * @see \App\Service\UserAdminService::setModeratorAreas()
 */
final class ModeratorAreaNoticeTest extends KernelTestCase
{
    private function user(string $email, array $roles = []): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail($email)->setDisplayName('Area '.substr(md5($email), 0, 6));
        $u->setPassword('x')->setEmailVerified(true)->setRoles($roles);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function region(string $slug, string $name): int
    {
        $db = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES (?, ?, ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 1000, 'BE', ?, 2, 'test', NOW(), NOW())",
            [$slug, $name, strtoupper(substr($slug, 0, 5))],
        );

        return (int) $db->lastInsertId('region_id_seq');
    }

    /** @return list<UserMessage> */
    private function noticesFor(User $u): array
    {
        return self::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(UserMessage::class)
            ->findBy(['userId' => (int) $u->getId(), 'kind' => UserMessageKind::ModeratorAreasChanged]);
    }

    public function testGainingAndLosingRegionsBothTellThem(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(UserAdminService::class);
        $target = $this->user('area-target@example.test', ['ROLE_CURATOR']);
        $actor = $this->user('area-actor@example.test', ['ROLE_ADMIN']);
        $one = $this->region('area-one', 'Area One');
        $two = $this->region('area-two', 'Area Two');

        $svc->setModeratorAreas($target, $actor, [], [$one]);
        self::assertCount(1, $this->noticesFor($target), 'given a region');

        $svc->setModeratorAreas($target, $actor, [], [$one, $two]);
        self::assertCount(2, $this->noticesFor($target), 'given another');

        $svc->setModeratorAreas($target, $actor, [], [$two]);
        $notices = $this->noticesFor($target);
        self::assertCount(3, $notices, 'and told when one is taken away too');

        // The message names what they hold NOW, not an id they cannot read.
        $last = end($notices);
        self::assertSame('Area Two', $last->getBodyParams()['%areas%'] ?? null);
    }

    public function testSavingTheSameAreasAgainSaysNothing(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(UserAdminService::class);
        $target = $this->user('area-quiet@example.test', ['ROLE_CURATOR']);
        $actor = $this->user('area-quiet-actor@example.test', ['ROLE_ADMIN']);
        $r = $this->region('area-quiet', 'Quiet Area');

        $svc->setModeratorAreas($target, $actor, [], [$r]);
        $svc->setModeratorAreas($target, $actor, [], [$r]);
        // Same set, opposite order: still not a change.
        $svc->setModeratorAreas($target, $actor, [], [$r]);

        self::assertCount(1, $this->noticesFor($target), 're-saving unchanged is not news');
    }

    /**
     * Empty means GLOBAL, which is the opposite of "nothing" — telling somebody
     * they now cover nothing when they cover everything would be the worst way
     * to be wrong about it.
     */
    public function testClearingEveryAreaReadsAsEverywhere(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(UserAdminService::class);
        $target = $this->user('area-global@example.test', ['ROLE_CURATOR']);
        $actor = $this->user('area-global-actor@example.test', ['ROLE_ADMIN']);
        $r = $this->region('area-global', 'Global Area');

        $svc->setModeratorAreas($target, $actor, [], [$r]);
        $svc->setModeratorAreas($target, $actor, [], []);

        $notices = $this->noticesFor($target);
        $last = end($notices);
        self::assertSame('everywhere', $last->getBodyParams()['%areas%'] ?? null);
    }
}
