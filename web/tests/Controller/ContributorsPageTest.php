<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Catalog\ConfirmationStance;
use App\Catalog\ContributorWallProvider;
use App\Catalog\Entity\ItemConfirmation;
use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class ContributorsPageTest extends WebTestCase
{
    private function rider(string $email, string $name, bool $publicProfile): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail($email)->setDisplayName($name);
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable())->setRoles([]);
        $u->setPublicProfile($publicProfile);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function approvedSubmission(int $userId, string $letter = 'N', SubmissionType $type = SubmissionType::NewItem): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $sub = (new Submission())->setType($type)->setLetter($letter)->setUserId($userId)
            ->setTitle('Wall submission')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $sub->setStatus(SubmissionStatus::Approved);
        $em->persist($sub);
        $em->flush();
    }

    public function testWallShowsOnlyOptInContributorsWithRealCounts(): void
    {
        $client = static::createClient();
        $public = $this->rider('wall-public@test.test', 'Wall Rider', true);
        $private = $this->rider('wall-private@test.test', 'Hidden Rider', false);
        $this->approvedSubmission((int) $public->getId());
        $this->approvedSubmission((int) $private->getId());
        // Opted in but nothing contributed — not on the wall either.
        $this->rider('wall-idle@test.test', 'Idle Rider', true);

        $client->request('GET', '/contributors');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Wall Rider', $html);
        self::assertStringContainsString('/riders/'.$public->getUuid()->toRfc4122(), $html, 'wall rows link the public profile');
        self::assertStringNotContainsString('Hidden Rider', $html, 'no opt-in, no wall — regardless of contributions');
        self::assertStringNotContainsString('Idle Rider', $html, 'opt-in without contributions stays off the wall');
        self::assertStringNotContainsString('rider#', $html, 'the sample handles are gone');
    }

    public function testEmptyWallIsHonest(): void
    {
        $client = static::createClient();
        $client->request('GET', '/contributors');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('rider#', $html);
        self::assertStringNotContainsString('312k', $html, 'demo stats are gone');
    }

    /**
     * The wall is paged, and the pager agrees with the rows it is paging:
     * the page query and the count query share one source builder.
     */
    public function testTheWallPagesAndCountsTheSameSet(): void
    {
        static::createClient();
        $wall = static::getContainer()->get(ContributorWallProvider::class);

        foreach (['Paged Alpha', 'Paged Bravo', 'Paged Charlie'] as $i => $name) {
            $rider = $this->rider(sprintf('wall-page-%d@test.test', $i), $name, true);
            $this->approvedSubmission((int) $rider->getId());
        }

        self::assertSame(3, $wall->wallCount('Paged '));

        $first = $wall->wall('Paged ', null, 1, 2);
        $second = $wall->wall('Paged ', null, 2, 2);
        self::assertCount(2, $first);
        self::assertCount(1, $second);
        // Alphabetical, exactly as the page copy promises, and the split does
        // not repeat or drop a rider across the boundary.
        self::assertSame(['Paged Alpha', 'Paged Bravo', 'Paged Charlie'], [
            ...array_column($first, 'name'),
            ...array_column($second, 'name'),
        ]);
    }

    /**
     * The search moved to the server when the wall became paged. A filter
     * that only saw the rendered page would tell a rider on page 4 that they
     * are not on the wall at all — so this asserts the filter reaches riders
     * the current page does NOT hold.
     */
    public function testTheSearchSpansTheWholeWallNotJustThePage(): void
    {
        $client = static::createClient();
        $wall = static::getContainer()->get(ContributorWallProvider::class);

        $needle = $this->rider('wall-needle@test.test', 'Zzz Needlerider', true);
        $this->approvedSubmission((int) $needle->getId());
        for ($i = 0; $i < 3; ++$i) {
            $other = $this->rider(sprintf('wall-hay-%d@test.test', $i), sprintf('Aaa Haystack %d', $i), true);
            $this->approvedSubmission((int) $other->getId());
        }

        // Last alphabetically, so a page of one cannot be holding them.
        self::assertSame([], array_filter(
            $wall->wall(null, null, 1, 1),
            static fn (array $r): bool => 'Zzz Needlerider' === $r['name'],
        ));

        $client->request('GET', '/contributors?q=Needlerider');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Zzz Needlerider', $html);
        self::assertStringNotContainsString('Aaa Haystack', $html, 'the filter narrows, it does not merely highlight');
    }

    /** A search that matches nobody says so, and does not read as an empty wall. */
    public function testANonMatchingSearchSaysNoMatchNotEmptyWall(): void
    {
        $client = static::createClient();
        $rider = $this->rider('wall-somebody@test.test', 'Somebody Real', true);
        $this->approvedSubmission((int) $rider->getId());

        $client->request('GET', '/contributors?q=nobody-by-that-name');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Somebody Real', $html);
        self::assertStringNotContainsString('Nobody on the wall yet', $html, 'a filtered-out wall is not an empty project');
    }

    /**
     * A name holding `%` searches for itself. Without escaping, one rider
     * called "100%" would match the entire wall.
     */
    public function testWildcardCharactersInASearchAreLiteral(): void
    {
        $client = static::createClient();
        $percent = $this->rider('wall-percent@test.test', 'Cent Percent 100%', true);
        $this->approvedSubmission((int) $percent->getId());
        $plain = $this->rider('wall-plain@test.test', 'Plain Namerider', true);
        $this->approvedSubmission((int) $plain->getId());

        $client->request('GET', '/contributors?q=100%25');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Cent Percent 100%', $html);
        self::assertStringNotContainsString('Plain Namerider', $html);
    }

    /**
     * The stat cards credit the crowd as a whole. Climbs, photos and checks
     * are the three kinds of work a rider can do that the facts and routes
     * cards did not show, and each follows the same approved-only boundary
     * the rider profile uses (account-and-auth.md §7).
     */
    public function testStatsCountClimbsPhotosAndChecksWithTheProfileBoundaries(): void
    {
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $wall = static::getContainer()->get(ContributorWallProvider::class);
        $before = $wall->stats();

        $rider = $this->rider('wall-stats@test.test', 'Stats Rider', false);
        $uid = (int) $rider->getId();

        // Two climbs added, one climb edit (a fact, not a new climb), one new tap.
        $this->approvedSubmission($uid, 'N');
        $this->approvedSubmission($uid, 'N');
        $this->approvedSubmission($uid, 'N', SubmissionType::Edit);
        $this->approvedSubmission($uid, 'B');

        // One live photo, one taken down, one still pending: only the first scores.
        $consent = new ConsentRecord(Uuid::v4(), $uid, MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);
        $live = new MediaUpload(Uuid::v4(), $uid, $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $live->approve(null);
        $em->persist($live);
        $gone = new MediaUpload(Uuid::v4(), $uid, $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $gone->approve(null);
        $gone->markObjectsDeleted();
        $em->persist($gone);
        $em->persist(new MediaUpload(Uuid::v4(), $uid, $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01'));
        $em->flush();

        // Two checks of either stance, one counter.
        $mkItem = static fn (string $ref): int => (int) $em->getConnection()->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at, imported_at)
             VALUES ('B', 'Wall Tap', ST_SetSRID(ST_MakePoint(6.0, 50.4), 4326), 'BE', 'unverified', 'osm', :ref, '{}', NOW(), NOW(), NOW())
             RETURNING id",
            ['ref' => $ref],
        );
        $em->persist(new ItemConfirmation($mkItem('osm:node:880001'), $uid, ConfirmationStance::Exists));
        $em->persist(new ItemConfirmation($mkItem('osm:node:880002'), $uid, ConfirmationStance::Potable));
        $em->flush();

        $after = $wall->stats();
        self::assertSame($before['climbs'] + 2, $after['climbs'], 'climbs added: approved new-climb submissions only');
        self::assertSame($before['photos'] + 1, $after['photos'], 'photos: approved and still stored');
        self::assertSame($before['checks'] + 2, $after['checks'], 'checks: every stance in one counter');
        self::assertSame($before['facts'] + 4, $after['facts'], 'the facts card still counts every approved submission');
    }

    /** Each wall row shows the same five kinds of work as the stat cards. */
    public function testEachRowShowsClimbsPhotosAndChecks(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $rider = $this->rider('wall-rowstats@test.test', 'Rowstats Rider', true);
        $uid = (int) $rider->getId();

        $this->approvedSubmission($uid, 'N');
        $this->approvedSubmission($uid, 'N');
        $this->approvedSubmission($uid, 'B');

        $consent = new ConsentRecord(Uuid::v4(), $uid, MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);
        $live = new MediaUpload(Uuid::v4(), $uid, $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $live->approve(null);
        $em->persist($live);
        $em->persist(new MediaUpload(Uuid::v4(), $uid, $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01'));
        $em->flush();

        $itemId = (int) $em->getConnection()->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at, imported_at)
             VALUES ('B', 'Row Tap', ST_SetSRID(ST_MakePoint(6.0, 50.4), 4326), 'BE', 'unverified', 'osm', 'osm:node:770001', '{}', NOW(), NOW(), NOW())
             RETURNING id",
        );
        $em->persist(new ItemConfirmation($itemId, $uid, ConfirmationStance::Exists));
        $em->flush();

        $crawler = $client->request('GET', '/contributors?q=Rowstats');
        self::assertResponseIsSuccessful();
        $row = $crawler->filter('.ack .row .st')->first()->text();
        self::assertStringContainsString('3 facts', $row);
        self::assertStringContainsString('2 climbs', $row);
        self::assertStringContainsString('1 photos', $row);
        self::assertStringContainsString('1 checks', $row);
        self::assertStringContainsString('0 routes', $row);
    }

    public function testTheStatCardsRenderClimbsPhotosAndChecks(): void
    {
        $client = static::createClient();
        $client->request('GET', '/contributors');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        foreach (['Climbs added', 'Photos shared', 'On-the-spot checks'] as $label) {
            self::assertStringContainsString($label, $html);
        }
    }

    /**
     * A curator's row says so. The role is a public office on the wall, not
     * a ranking: the chip carries no count and changes no order.
     */
    public function testACuratorRowCarriesTheCuratorChipAndARiderRowDoesNot(): void
    {
        $client = static::createClient();
        $curator = $this->rider('wall-curator@test.test', 'Chip Curator', true);
        $curator->setRoles(['ROLE_CURATOR']);
        $admin = $this->rider('wall-admin@test.test', 'Chip Admin', true);
        $admin->setRoles(['ROLE_ADMIN']);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $rider = $this->rider('wall-rider@test.test', 'Chip Rider', true);
        foreach ([$curator, $admin, $rider] as $u) {
            $this->approvedSubmission((int) $u->getId());
        }

        $crawler = $client->request('GET', '/contributors?q=Chip');
        self::assertResponseIsSuccessful();

        $rows = $crawler->filter('.ack .row');
        self::assertCount(3, $rows);
        $byName = [];
        foreach ($rows as $row) {
            $node = new \Symfony\Component\DomCrawler\Crawler($row);
            $byName[$node->filter('.nm')->text()] = $node->filter('.role')->count();
        }
        self::assertSame(1, $byName['Chip Curator']);
        self::assertSame(1, $byName['Chip Admin'], 'an admin moderates too, so the chip shows');
        self::assertSame(0, $byName['Chip Rider']);
        self::assertStringContainsString('Curator', $crawler->filter('.ack .row .role')->first()->text());
    }
}
