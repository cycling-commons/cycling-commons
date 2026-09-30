<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\CatalogFindingRepository;
use App\Catalog\Entity\CatalogFinding;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Submission;
use App\Catalog\FindingKind;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaModerationEvent;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaConsent;
use App\Media\MediaDecisionService;
use App\Media\MediaStorage;
use App\Media\MediaTakedownService;
use App\Media\ProcessedPhoto;
use App\Messaging\CuratorRoom;
use App\Moderation\ModerationScope;
use App\Moderation\RouteQueue;
use App\Moderation\SubmissionQueue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * The moderation bar's badges count open work, and every desk shows the same
 * numbers: a badge that is on one page and gone on the next reads as though
 * visiting cleared it. They go down when the work is decided, never because a
 * page was visited. Data sits under More, so More carries its count too.
 *
 * @see docs/specs/moderation-and-contribution.md §5.0
 */
final class DeskBadgesTest extends WebTestCase
{
    /** Desks with a queue of their own, and a few without one. */
    private const array PAGES = [
        '/moderate',
        '/moderate/bugs',
        '/moderate/submissions',
        '/moderate/submissions/history',
        '/moderate/routes',
        '/moderate/data',
        '/moderate/takedowns',
        '/moderate/reports',
        '/moderate/translations',
        '/moderate/room',
        '/moderate/regions',
        '/moderate/rulebook',
    ];

    private int $seq = 0;

    public function testEveryDeskShowsTheSameBadges(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $curator = $this->seedOpenWork();
        $client->loginUser($curator);

        $scope = ModerationScope::global();
        $container = static::getContainer();
        $expected = [
            'submissions' => (string) $container->get(SubmissionQueue::class)->total($scope),
            'routes' => (string) ($container->get(RouteQueue::class)->total($scope) + $container->get(RouteQueue::class)->pendingSuggestionCount($scope)),
            'takedowns' => (string) $container->get(MediaTakedownService::class)->pendingCount(),
            'data' => (string) $container->get(CatalogFindingRepository::class)->openCount($scope),
        ];
        $expected['more'] = $expected['data'];
        $expected['room'] = (string) $container->get(CuratorRoom::class)->unreadCount((int) $curator->getId());
        foreach ($expected as $desk => $n) {
            self::assertGreaterThan(0, (int) $n, $desk.' has open work to show');
        }

        foreach (self::PAGES as $page) {
            $crawler = $client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
            self::assertSame($expected, $this->badges($crawler), 'the badges on '.$page);
        }
    }

    public function testVisitingADeskChangesNoBadge(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $curator = $this->seedOpenWork();
        $client->loginUser($curator);

        $before = $this->badges($client->request('GET', '/moderate/bugs'));
        self::assertNotContains(null, $before, 'the Bugs page shows every desk\'s badge');
        foreach (self::PAGES as $page) {
            $client->request('GET', $page);
        }
        self::assertSame($before, $this->badges($client->request('GET', '/moderate/bugs')));
    }

    /**
     * @return array<string, string|null>
     */
    private function badges(Crawler $crawler): array
    {
        $bar = $crawler->filter('.dtabs-modmode');
        self::assertCount(1, $bar);
        $read = static function (Crawler $c): ?string {
            return $c->count() > 0 ? trim($c->text()) : null;
        };

        return [
            'submissions' => $read($bar->filter('.dtabs-strip a[href$="/moderate/submissions"] .dtab-count')),
            'routes' => $read($bar->filter('.dtabs-strip a[href$="/moderate/routes"] .dtab-count')),
            'takedowns' => $read($bar->filter('.dtabs-strip a[href$="/moderate/takedowns"] .dtab-count')),
            'data' => $read($bar->filter('#cc-mod-more a[href$="/moderate/data"] .dtab-count')),
            'more' => $read($bar->filter('.dtabs-more-btn .dtab-count')),
            'room' => $read($bar->filter('.dtabs-strip a[href$="/moderate/room"] .dtab-count')),
        ];
    }

    /** A global curator, and one piece of open work on each counted desk. */
    private function seedOpenWork(): User
    {
        $em = $this->em();
        $curator = (new User())->setEmail('badges-'.bin2hex(random_bytes(4)).'@example.test')->setDisplayName('Badge Curator');
        $curator->setEmailVerified(true);
        $curator->setEmailVerifiedAt(new \DateTimeImmutable());
        $curator->setRoles(['ROLE_CURATOR']);
        $curator->setPassword('x');
        $curator->setTotpSecret('JBSWY3DPEHPK3PXP');
        $curator->setTwoFaEnabled(true);
        $em->persist($curator);
        $rider = (new User())->setEmail('badges-rider-'.bin2hex(random_bytes(4)).'@example.test')->setDisplayName('Badge Rider');
        $rider->setPassword('x');
        $em->persist($rider);
        $em->flush();

        $em->persist((new Submission())->setType(SubmissionType::NewItem)->setLetter('A')->setUserId((int) $rider->getId())
            ->setTitle('Badge fountain')->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]));

        $em->persist((new RecommendedRoute())->setName('Badge loop')
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState(ItemState::Submitted)
            ->setSource(ItemSource::User)->setSourceRef('user:badge-'.bin2hex(random_bytes(4)))->setProposedBy((int) $rider->getId()));

        $em->persist(new CatalogFinding(FindingKind::Duplicate, $this->item('finding'), ['distanceM' => 180.0, 'osmName' => 'Dom']));
        $em->flush();

        static::getContainer()->get(MediaTakedownService::class)->request($this->approvedPhoto($rider), 'Please remove it.');

        $colleague = (new User())->setEmail('badges-colleague-'.bin2hex(random_bytes(4)).'@example.test')->setDisplayName('Badge Colleague');
        $colleague->setRoles(['ROLE_CURATOR']);
        $colleague->setPassword('x');
        $em->persist($colleague);
        $em->flush();
        static::getContainer()->get(CuratorRoom::class)->post((int) $colleague->getId(), null, null, 'Seen this one?', title: 'Badge post');

        return $curator;
    }

    private function item(string $slug): Item
    {
        $item = (new Item())->setLetter('A')->setName('Badge place '.++$this->seq)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('badges-'.$slug.'-'.bin2hex(random_bytes(4)))
            ->setSource(ItemSource::User)->setState(ItemState::Verified)->setAttributes([]);
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    /** An approved photo on an item, as approval leaves it, so a takedown can be asked for. */
    private function approvedPhoto(User $owner): MediaUpload
    {
        $em = $this->em();
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);
        $item = $this->item('photo');

        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $em->persist($upload);
        $em->persist(new MediaModerationEvent($upload->getId(), (int) $owner->getId(), MediaAction::Uploaded));
        $upload->approve($item->getId());
        $em->flush();

        static::getContainer()->get(MediaStorage::class)->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));
        $item->setAttributes(['photos' => [static::getContainer()->get(MediaDecisionService::class)->describe($upload)]]);
        $em->flush();

        return $upload;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
