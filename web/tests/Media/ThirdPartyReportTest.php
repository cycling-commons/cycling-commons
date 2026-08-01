<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaModerationEvent;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaConsent;
use App\Media\MediaDecisionService;
use App\Media\MediaStorage;
use App\Media\MediaTakedownCategory;
use App\Media\MediaTakedownService;
use App\Media\MediaTakedownSource;
use App\Media\ProcessedPhoto;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Takedown requests from people who are IN a photo somebody else uploaded
 * (docs/specs/photo-uploads.md §6c). The asymmetry under test: a stranger's
 * report queues and changes nothing — except the intimate-imagery/child
 * category, which withholds on the spot — and a decided category is final.
 */
final class ThirdPartyReportTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MediaTakedownService $takedowns;
    private MediaStorage $storage;
    private FilesystemOperator $filesystem;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->takedowns = static::getContainer()->get(MediaTakedownService::class);
        $this->storage = static::getContainer()->get(MediaStorage::class);
        $this->filesystem = static::getContainer()->get('media.storage.eu');
    }

    private function rider(string $email): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Report Rider');
        $user->setPublicProfile(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private int $itemSeq = 0;

    /** An approved photo, stored and attached to an item, exactly as approval leaves it. */
    private function approved(User $owner): MediaUpload
    {
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);

        $item = (new Item())->setLetter('A')->setName('Fontaine du regard')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('report-test-'.++$this->itemSeq)
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $this->em->persist($item);
        $this->em->flush();

        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242);
        $this->em->persist($upload);
        $this->em->persist(new MediaModerationEvent($upload->getId(), (int) $owner->getId(), MediaAction::Uploaded));
        $upload->approve($item->getId());
        $this->em->flush();

        $this->storage->store('EU', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));

        $entry = static::getContainer()->get(MediaDecisionService::class)->describe($upload);
        $item->setAttributes(['photos' => [$entry]]);
        $this->em->flush();

        return $upload;
    }

    private function itemOf(MediaUpload $upload): Item
    {
        $item = $this->em->find(Item::class, (int) $upload->getItemId());
        self::assertInstanceOf(Item::class, $item);

        return $item;
    }

    /** @return list<string> */
    private function actions(MediaUpload $upload): array
    {
        return array_map(
            static fn (MediaModerationEvent $e): string => $e->getAction(),
            $this->em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $upload->getId()], ['id' => 'ASC']),
        );
    }

    /** @return list<UserMessage> */
    private function messagesFor(User $user): array
    {
        return $this->em->getRepository(UserMessage::class)->findBy(['userId' => (int) $user->getId()]);
    }

    public function testAReportQueuesAndChangesNothingVisible(): void
    {
        $upload = $this->approved($this->rider('report-queue@example.com'));

        $this->takedowns->report($upload, MediaTakedownCategory::IdentifiableSelf, 'That is me at the fountain.', 'me@example.org', '203.0.113.7');

        self::assertTrue($upload->isTakedownPending(), 'a curator has work');
        self::assertFalse($upload->isTakedownWithheld(), 'the photo is NOT withheld');
        self::assertSame(MediaTakedownSource::ThirdParty, $upload->getTakedownSource());
        self::assertSame(MediaTakedownCategory::IdentifiableSelf, $upload->getTakedownCategory());
        self::assertSame('me@example.org', $upload->getTakedownContact());
        self::assertNotNull($upload->getTakedownReporterHash());
        self::assertStringNotContainsString('203.0.113.7', (string) $upload->getTakedownReporterHash(), 'no raw IP is stored');
        // Still on the map, still stored, still served.
        self::assertCount(1, $this->itemOf($upload)->getAttributes()['photos']);
        self::assertTrue($this->filesystem->fileExists($upload->getPathPrefix().'/sm.webp'));
        self::assertContains(MediaAction::ThirdPartyReported, $this->actions($upload));
    }

    public function testTheUrgentCategoryWithholdsOnTheSpotAndItsUseIsLogged(): void
    {
        $upload = $this->approved($this->rider('report-urgent@example.com'));

        $this->takedowns->report($upload, MediaTakedownCategory::IntimateOrChild, 'A child is clearly the subject.', null, '203.0.113.8');

        self::assertTrue($upload->isTakedownWithheld());
        self::assertArrayNotHasKey('photos', $this->itemOf($upload)->getAttributes());
        // Withheld, not destroyed — the curator still has to see it.
        self::assertTrue($this->filesystem->fileExists($upload->getPathPrefix().'/sm.webp'));

        $events = $this->em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $upload->getId()], ['id' => 'ASC']);
        $report = array_values(array_filter($events, static fn (MediaModerationEvent $e): bool => MediaAction::ThirdPartyReported === $e->getAction()));
        self::assertCount(1, $report);
        self::assertStringContainsString('(auto-withheld)', (string) $report[0]->getNote());
    }

    public function testIneligiblePhotosSwallowTheReportSilently(): void
    {
        $owner = $this->rider('report-ineligible@example.com');
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);
        $pending = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242);
        $this->em->persist($pending);
        $this->em->flush();

        $this->takedowns->report($pending, MediaTakedownCategory::IdentifiableSelf, 'x', null, '203.0.113.9');

        self::assertFalse($pending->isTakedownPending());
        self::assertNull($pending->getTakedownSource());
        self::assertNotContains(MediaAction::ThirdPartyReported, $this->actions($pending));
    }

    public function testASecondReportWhileOneWaitsDoesNotOverwriteIt(): void
    {
        $upload = $this->approved($this->rider('report-second@example.com'));

        $this->takedowns->report($upload, MediaTakedownCategory::IdentifiableSelf, 'First.', null, '203.0.113.10');
        $this->takedowns->report($upload, MediaTakedownCategory::Other, 'Second.', null, '203.0.113.11');

        self::assertSame('First.', $upload->getTakedownReason());
        self::assertSame(MediaTakedownCategory::IdentifiableSelf, $upload->getTakedownCategory());
    }

    public function testGrantingAQueuedReportRemovesThePhotoAndTellsTheUploaderNeutrally(): void
    {
        $owner = $this->rider('report-grant@example.com');
        $curator = $this->rider('report-curator@example.com');
        $upload = $this->approved($owner);
        $prefix = $upload->getPathPrefix();

        $this->takedowns->report($upload, MediaTakedownCategory::IdentifiableOther, 'My friend is in this and did not agree.', 'friend@example.org', '203.0.113.12');
        $this->takedowns->grant($upload, $curator, 'Person clearly identifiable.');

        // Granting a QUEUED report is the moment the photo leaves the gallery.
        self::assertArrayNotHasKey('photos', $this->itemOf($upload)->getAttributes());
        foreach (MediaStorage::VARIANTS as $variant) {
            self::assertFalse($this->filesystem->fileExists($prefix.'/'.$variant.'.webp'), "{$variant} is gone");
        }
        self::assertNotNull($upload->getTakedownResolvedAt());
        // The reply address survives the decision — the curator still owes the
        // reporter an answer within the month; the GC sweeps it at 90 days.
        self::assertSame('friend@example.org', $upload->getTakedownContact());

        $messages = $this->messagesFor($owner);
        self::assertCount(1, $messages);
        self::assertSame(UserMessageKind::MediaRemovedOnReport, $messages[0]->getKind());
    }

    public function testDecliningTellsTheUploaderNothingAndIsFinalForTheCategory(): void
    {
        $owner = $this->rider('report-decline@example.com');
        $curator = $this->rider('report-curator2@example.com');
        $upload = $this->approved($owner);

        $this->takedowns->report($upload, MediaTakedownCategory::PrivateProperty, 'That is my garden.', null, '203.0.113.13');
        $this->takedowns->decline($upload, $curator, 'The photo shows a public fountain.');

        self::assertFalse($upload->isTakedownPending());
        self::assertCount(1, $this->itemOf($upload)->getAttributes()['photos'], 'never left the map');
        self::assertSame([], $this->messagesFor($owner), 'nothing changed for the uploader, so they hear nothing');
        self::assertTrue($upload->hasDecidedTakedown(MediaTakedownCategory::PrivateProperty));

        // The same claim again: matched against the decided category, swallowed.
        $this->takedowns->report($upload, MediaTakedownCategory::PrivateProperty, 'My garden, again.', null, '203.0.113.14');
        self::assertFalse($upload->isTakedownPending(), 'a decided category cannot be re-opened');

        // A DIFFERENT claim still gets its look.
        $this->takedowns->report($upload, MediaTakedownCategory::IdentifiableSelf, 'Also, that is me.', null, '203.0.113.15');
        self::assertTrue($upload->isTakedownPending());
    }

    public function testDecliningAnUrgentReportRepublishes(): void
    {
        $owner = $this->rider('report-urgent-decline@example.com');
        $curator = $this->rider('report-curator3@example.com');
        $upload = $this->approved($owner);
        $before = $this->itemOf($upload)->getAttributes()['photos'];

        $this->takedowns->report($upload, MediaTakedownCategory::IntimateOrChild, 'Looks underage to me.', null, '203.0.113.16');
        self::assertArrayNotHasKey('photos', $this->itemOf($upload)->getAttributes());

        $this->takedowns->decline($upload, $curator, 'Adults, and nobody identifiable.');

        self::assertSame($before, $this->itemOf($upload)->getAttributes()['photos']);
        self::assertTrue($this->filesystem->fileExists($upload->getPathPrefix().'/sm.webp'));
    }

    public function testTheDeskCardCarriesSourceCategoryContactAndState(): void
    {
        $upload = $this->approved($this->rider('report-desk@example.com'));

        $this->takedowns->report($upload, MediaTakedownCategory::IdentifiableSelf, 'Me, foreground.', 'reply@example.org', '203.0.113.17');

        $cards = $this->takedowns->pendingCards();
        self::assertCount(1, $cards);
        self::assertSame(MediaTakedownSource::ThirdParty, $cards[0]['source']);
        self::assertSame(MediaTakedownCategory::IdentifiableSelf, $cards[0]['category']);
        self::assertSame('reply@example.org', $cards[0]['contact']);
        self::assertFalse($cards[0]['withheld']);
    }

    public function testContactsAreSweptNinetyDaysAfterResolutionAndNotBefore(): void
    {
        $owner = $this->rider('report-sweep@example.com');
        $curator = $this->rider('report-curator4@example.com');
        $old = $this->approved($owner);
        $fresh = $this->approved($owner);

        $this->takedowns->report($old, MediaTakedownCategory::IdentifiableSelf, 'Old one.', 'old@example.org', '203.0.113.18');
        $this->takedowns->decline($old, $curator);
        $this->takedowns->report($fresh, MediaTakedownCategory::IdentifiableSelf, 'Fresh one.', 'fresh@example.org', '203.0.113.19');
        $this->takedowns->decline($fresh, $curator);

        $this->em->getConnection()->executeStatement(
            "UPDATE media_upload SET takedown_resolved_at = NOW() - INTERVAL '91 days' WHERE id = ?",
            [$old->getId()->toRfc4122()],
        );
        $this->em->refresh($old);

        $cleared = $this->takedowns->purgeExpiredContacts(new \DateTimeImmutable());

        self::assertSame(1, $cleared);
        self::assertNull($old->getTakedownContact());
        self::assertSame('fresh@example.org', $fresh->getTakedownContact());
    }

    public function testTheUploaderRouteStampsItsSource(): void
    {
        $upload = $this->approved($this->rider('report-source@example.com'));

        $this->takedowns->request($upload, 'My own photo, my own face.');

        self::assertSame(MediaTakedownSource::Uploader, $upload->getTakedownSource());
        self::assertTrue($upload->isTakedownWithheld(), 'the uploader route still withholds on the spot');
    }
}
