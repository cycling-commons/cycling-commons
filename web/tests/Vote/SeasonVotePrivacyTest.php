<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Vote;

use App\Account\DataExportService;
use App\Entity\User;
use App\Service\UserDeletionService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

final class SeasonVotePrivacyTest extends KernelTestCase
{
    private const int REGION = 900002;

    private Connection $db;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        Clock::set(new MockClock(new \DateTimeImmutable('2027-07-01T12:00:00+00:00')));
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    private function rider(): User
    {
        $u = (new User())->setEmail('priv-'.bin2hex(random_bytes(4)).'@test.test')->setDisplayName('Rider');
        $u->setEmailVerified(true)->setRoles([])->setPassword('not-a-real-hash');
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function vote(User $u, int $subject, int $slot, string $start = '2027-03-01', string $season = 'spring'): void
    {
        $this->db->insert('season_vote', [
            'user_id' => $u->getId(), 'region_id' => self::REGION, 'category' => 'climbs', 'subject_id' => $subject,
            'bike_type' => null, 'season' => $season, 'round_start' => $start, 'slot' => $slot, 'created_at' => $start.' 10:00:00',
        ]);
    }

    public function testAClosedRoundKeepsADeletedRidersVoteInItsTotals(): void
    {
        $riders = [$this->rider(), $this->rider(), $this->rider(), $this->rider(), $this->rider()];
        foreach ($riders as $i => $u) {
            $this->vote($u, 3001, 1);
            if ($i < 3) {
                $this->vote($u, 3002, 2);
            }
        }
        // The open summer round holds one vote of the rider who leaves.
        $this->vote($riders[0], 3001, 1, '2027-06-01', 'summer');
        $leaving = (int) $riders[0]->getId();

        static::getContainer()->get(UserDeletionService::class)->purge($riders[0]);
        $this->em->flush();

        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM season_vote WHERE user_id = ?', [$leaving]));
        $stored = $this->db->fetchAllAssociative(
            "SELECT subject_id, votes, voters, place FROM season_result WHERE region_id = ? AND round_start = '2027-03-01' ORDER BY list_position",
            [self::REGION],
        );
        self::assertSame([[3001, 5, 5, 1], [3002, 3, 5, 2]], array_map(static fn (array $r): array => [(int) $r['subject_id'], (int) $r['votes'], (int) $r['voters'], (int) $r['place']], $stored));
        self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM season_result WHERE round_start = '2027-06-01' AND region_id = ?", [self::REGION]), 'the open round is not stored');
    }

    public function testTheExportCarriesTheRidersOwnVotes(): void
    {
        $u = $this->rider();
        $this->vote($u, 3001, 1);
        $this->vote($u, 3002, 1, '2027-06-01', 'summer');
        $this->vote($this->rider(), 3003, 1);

        $path = static::getContainer()->get(DataExportService::class)->export($u);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $community = json_decode((string) $zip->getFromName('community.json'), true, 512, \JSON_THROW_ON_ERROR);
        $zip->close();
        unlink($path);

        self::assertIsArray($community);
        self::assertSame([3001, 3002], array_map(static fn (array $v): int => (int) $v['subject_id'], $community['season_votes']));
        self::assertSame(['climbs', 'climbs'], array_column($community['season_votes'], 'category'));
        self::assertSame(['2027-03-01', '2027-06-01'], array_column($community['season_votes'], 'round_start'));
    }
}
