<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\HeldPhotoNotRestored;
use App\Media\MediaAction;
use App\Media\MediaConsent;
use App\Media\MediaDecisionService;
use App\Media\MediaDisposalService;
use App\Media\MediaEscalationService;
use App\Media\MediaEventLog;
use App\Media\MediaStorage;
use App\Media\MediaTakedownCategory;
use App\Media\MediaTakedownService;
use App\Media\MediaTakedownSource;
use App\Media\PhotoGallery;
use App\Media\ProcessedPhoto;
use App\Media\UrgentWithholdBreaker;
use App\Messaging\MessageService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToWriteFile;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Escalation and the legal hold (docs/specs/photo-uploads.md §6d).
 *
 * The property that matters more than any other here: **nothing may destroy a
 * held row**. Trash, the retention sweep, orphan collection and a granted
 * takedown all had a path to deletion before, and where the material is the
 * kind that must be reported, deleting it destroys the evidence with it.
 */
final class MediaEscalationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MediaEscalationService $escalations;
    private MediaTakedownService $takedowns;
    private MediaDisposalService $disposal;
    private MediaStorage $storage;
    private FilesystemOperator $filesystem;
    private FilesystemOperator $private;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escalations = static::getContainer()->get(MediaEscalationService::class);
        $this->takedowns = static::getContainer()->get(MediaTakedownService::class);
        $this->disposal = static::getContainer()->get(MediaDisposalService::class);
        $this->storage = static::getContainer()->get(MediaStorage::class);
        $this->filesystem = static::getContainer()->get('media.storage.eu01');
        $this->private = static::getContainer()->get('media.storage.private');
    }

    private function rider(string $email): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Escalation Rider');
        $user->setPublicProfile(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private int $seq = 0;

    private function approved(User $owner): MediaUpload
    {
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);

        $item = (new Item())->setLetter('A')->setName('Fontaine tenue')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('escalation-test-'.++$this->seq)
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $this->em->persist($item);
        $this->em->flush();

        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $this->em->persist($upload);
        $upload->approve($item->getId());
        $this->em->flush();

        $this->storage->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));
        $item->setAttributes(['photos' => [static::getContainer()->get(MediaDecisionService::class)->describe($upload)]]);
        $this->em->flush();

        return $upload;
    }

    private function itemOf(MediaUpload $upload): Item
    {
        $item = $this->em->find(Item::class, (int) $upload->getItemId());
        self::assertInstanceOf(Item::class, $item);

        return $item;
    }

    public function testEscalatingHidesItFromEveryoneAndAlertsAHuman(): void
    {
        $curator = $this->rider('escalate-curator@example.com');
        $upload = $this->approved($this->rider('escalate-owner@example.com'));

        $this->escalations->escalate($upload, $curator, 'Appears to show a minor.');

        self::assertTrue($upload->isEscalated());
        self::assertArrayNotHasKey('photos', $this->itemOf($upload)->getAttributes(), 'off the map');
        // Off the curator desk too — the person who escalated it never has to
        // see it again, and nobody else ever does.
        self::assertSame([], $this->takedowns->pendingCards());
        self::assertSame([], $this->takedowns->withheldThirdPartyCards());
        // Out of the public bucket: its URL was on the map, so it sits in tiles
        // and caches. Still stored, privately, which is the whole point.
        foreach (MediaStorage::VARIANTS as $variant) {
            self::assertFalse($this->filesystem->fileExists($upload->getPathPrefix().'/'.$variant.'.webp'), "public {$variant} is gone");
            self::assertSame('S', $this->private->read('held/'.$upload->getPathPrefix().'/sm.webp'));
        }
        self::assertSame(3, $this->storage->heldVariantsExist($upload->getPathPrefix()));

        $sent = static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getEvents();
        self::assertCount(1, $sent, 'unthrottled: every escalation reaches a human');
        $email = $sent[0]->getMessage();
        self::assertInstanceOf(Email::class, $email);
        $body = (string) $email->getTextBody();
        self::assertStringContainsString('Appears to show a minor.', $body);
        self::assertStringContainsString('/admin/escalated', $body);
        // The alert must never carry the material itself into a mailbox.
        self::assertSame([], $email->getAttachments());
        self::assertNull($email->getHtmlBody());
    }

    public function testNothingCanDestroyAHeldPhoto(): void
    {
        $curator = $this->rider('escalate-hold@example.com');
        $upload = $this->approved($this->rider('escalate-hold-owner@example.com'));
        $prefix = $upload->getPathPrefix();

        $this->escalations->escalate($upload, $curator, 'Suspected illegal content.');

        // Trash / account deletion / orphan collection all funnel through purge().
        $this->disposal->purge($upload);
        // The retention sweep funnels through deleteObjects().
        $this->disposal->deleteObjects($upload);
        // And a curator cannot decide their way to a deletion either.
        $this->takedowns->grant($upload, $curator);

        self::assertNotNull($this->em->find(MediaUpload::class, $upload->getId()), 'the row survives');
        self::assertNull($upload->getObjectsDeletedAt(), 'the tombstone was never set');
        foreach (MediaStorage::VARIANTS as $variant) {
            self::assertTrue($this->private->fileExists('held/'.$prefix.'/'.$variant.'.webp'), "{$variant} survives");
        }
    }

    public function testAHeldPhotoIsBeyondTheTakedownVerbsAndTheRestoreSweep(): void
    {
        $curator = $this->rider('escalate-verbs@example.com');
        $owner = $this->rider('escalate-verbs-owner@example.com');
        $upload = $this->approved($owner);

        $this->takedowns->report($upload, MediaTakedownCategory::IntimateOrChild, 'A child is the subject.', null, '203.0.113.90');
        $this->escalations->escalate($upload, $curator, 'Confirmed, escalating.');

        self::assertFalse($this->takedowns->dismissAsAbuse($upload, $curator), 'a bulk restore cannot reach it');
        $this->takedowns->decline($upload, $curator);
        self::assertArrayNotHasKey('photos', $this->itemOf($upload)->getAttributes(), 'and cannot put it back on the map');
    }

    public function testEscalatingTwiceAlertsOnce(): void
    {
        $curator = $this->rider('escalate-twice@example.com');
        $upload = $this->approved($this->rider('escalate-twice-owner@example.com'));

        $this->escalations->escalate($upload, $curator, 'First.');
        $this->escalations->escalate($upload, $curator, 'Double submit.');

        $sent = static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getEvents();
        self::assertCount(1, $sent);
        self::assertSame('First.', $upload->getEscalatedReason());
    }

    public function testAnEmptyReasonIsRefused(): void
    {
        $curator = $this->rider('escalate-empty@example.com');
        $upload = $this->approved($this->rider('escalate-empty-owner@example.com'));

        $this->expectException(\InvalidArgumentException::class);
        $this->escalations->escalate($upload, $curator, '  ');
    }

    public function testReleasingHandsThePhotoToTheTakedownDeskStillWithheld(): void
    {
        $curator = $this->rider('escalate-release@example.com');
        $admin = $this->rider('escalate-release-admin@example.com');
        $upload = $this->approved($this->rider('escalate-release-owner@example.com'));

        $this->escalations->escalate($upload, $curator, 'Not sure, escalating.');
        self::assertTrue($this->escalations->release($upload, $admin, 'False alarm.'));

        self::assertFalse($upload->isEscalated());
        // Release puts nothing back by itself (owner 2026-10-11): the photo
        // waits on the takedown desk, withheld, for a curator to decide.
        self::assertTrue($upload->isTakedownWithheld());
        self::assertSame(MediaTakedownSource::LegalHold, $upload->getTakedownSource());
        self::assertSame(MediaEscalationService::RELEASED_REASON, $upload->getTakedownReason(), 'the desk reads fixed words, never the escalating curator\'s');
        self::assertArrayNotHasKey('photos', $this->itemOf($upload)->getAttributes());
        // The bytes stay private, so neither the photo page nor the image address serves it.
        self::assertSame(0, $this->storage->variantsExist('test-bucket-eu-01', $upload->getPathPrefix()));
        self::assertSame(3, $this->storage->heldVariantsExist($upload->getPathPrefix()));
        self::assertContains($upload->getId()->toRfc4122(), array_column($this->takedowns->pendingCards(), 'uuid'));
        $actions = array_map(
            static fn (\App\Media\Entity\MediaModerationEvent $e): string => $e->getAction(),
            $this->em->getRepository(\App\Media\Entity\MediaModerationEvent::class)->findBy(['mediaId' => $upload->getId()], ['id' => 'ASC']),
        );
        self::assertContains(MediaAction::Escalated, $actions);
        self::assertContains(MediaAction::EscalationReleased, $actions);
        // Released rows leave the admin worklist.
        self::assertSame([], $this->escalations->held());
    }

    public function testTheAdminWorklistCarriesTheCuratorsWords(): void
    {
        $curator = $this->rider('escalate-list@example.com');
        $upload = $this->approved($this->rider('escalate-list-owner@example.com'));

        $this->escalations->escalate($upload, $curator, 'Two adults, one appears coerced.');

        $held = $this->escalations->held();
        self::assertCount(1, $held);
        self::assertSame('Two adults, one appears coerced.', $held[0]['reason']);
        self::assertSame('Escalation Rider', $held[0]['escalatedBy']);
        self::assertSame($upload->getId()->toRfc4122(), $held[0]['uuid']);
    }

    public function testEscalatingAgainMovesNothingTwice(): void
    {
        $curator = $this->rider('escalate-idem@example.com');
        $upload = $this->approved($this->rider('escalate-idem-owner@example.com'));

        $this->escalations->escalate($upload, $curator, 'First.');
        $this->escalations->withholdObjects($upload);

        self::assertSame(3, $this->storage->heldVariantsExist($upload->getPathPrefix()));
        self::assertSame(0, $this->storage->variantsExist('test-bucket-eu-01', $upload->getPathPrefix()));
    }

    public function testAnAdminSeesTheHeldPhotoAndNobodyElseCan(): void
    {
        $curator = $this->rider('escalate-view@example.com');
        $upload = $this->approved($this->rider('escalate-view-owner@example.com'));

        $stream = $this->escalations->heldStream($upload, 'sm');
        self::assertNull($stream, 'a photo that is not held has no held copy to show');

        $this->escalations->escalate($upload, $curator, 'Escalating.');
        $stream = $this->escalations->heldStream($upload, 'sm');
        self::assertIsResource($stream);
        self::assertSame('S', stream_get_contents($stream));
    }

    public function testDecliningAReleasedPhotoPutsItBackQuietly(): void
    {
        $curator = $this->rider('release-decline@example.com');
        $owner = $this->rider('release-decline-owner@example.com');
        $upload = $this->approved($owner);
        $this->escalations->escalate($upload, $curator, 'Escalating.');
        $this->escalations->release($upload, $this->rider('release-decline-admin@example.com'));

        $this->takedowns->decline($upload, $curator, 'Fine to show.');

        self::assertFalse($upload->isTakedownPending());
        self::assertCount(1, $this->itemOf($upload)->getAttributes()['photos'] ?? [], 'back on the map');
        self::assertSame(3, $this->storage->variantsExist('test-bucket-eu-01', $upload->getPathPrefix()), 'and its address works again');
        self::assertSame(0, $this->storage->heldVariantsExist($upload->getPathPrefix()));
        // The hold told the uploader nothing, so its end does not either.
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM user_message WHERE user_id = ?', [$owner->getId()]));
    }

    public function testGrantingAReleasedPhotoRemovesItWithTheReasons(): void
    {
        $curator = $this->rider('release-grant@example.com');
        $owner = $this->rider('release-grant-owner@example.com');
        $upload = $this->approved($owner);
        $this->escalations->escalate($upload, $curator, 'Escalating.');
        $this->escalations->release($upload, $this->rider('release-grant-admin@example.com'));

        $this->takedowns->grant($upload, $curator, 'It shows a person who did not agree.');

        self::assertNotNull($upload->getObjectsDeletedAt());
        self::assertSame(0, $this->storage->heldVariantsExist($upload->getPathPrefix()), 'gone everywhere');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM user_message WHERE user_id = ? AND kind = 'media_removed_on_report'",
            [$owner->getId()],
        ), 'a removal of what they added comes with its statement of reasons');
    }

    public function testAFailedMoveBackOnDeclineKeepsThePhotoWithheld(): void
    {
        $curator = $this->rider('escalate-stuck@example.com');
        $admin = $this->rider('escalate-stuck-admin@example.com');
        $upload = $this->approved($this->rider('escalate-stuck-owner@example.com'));
        $this->escalations->escalate($upload, $curator, 'Escalating.');
        $this->escalations->release($upload, $admin);

        // The public shard refuses writes, so the variants cannot go back.
        $brokenPublic = $this->createStub(FilesystemOperator::class);
        $brokenPublic->method('writeStream')->willThrowException(UnableToWriteFile::atLocation('x', 'shard down'));
        $c = static::getContainer();
        $takedowns = new MediaTakedownService(
            $this->em,
            $c->get(Connection::class),
            $c->get(MediaEventLog::class),
            $this->disposal,
            $c->get(MediaDecisionService::class),
            $c->get(PhotoGallery::class),
            $c->get(MessageService::class),
            $c->get(UrgentWithholdBreaker::class),
            new MediaStorage(null, [], 'test-bucket-private', 'https://img.test', ['test-bucket-eu-01' => $brokenPublic, 'test-bucket-private' => $this->private]),
            new NullLogger(),
            $c->get(UrlGeneratorInterface::class),
            'test-secret',
        );

        try {
            $takedowns->decline($upload, $curator, 'Fine.');
            self::fail('a decline whose objects cannot go back must change nothing');
        } catch (HeldPhotoNotRestored) {
        }

        self::assertTrue($upload->isTakedownWithheld());
        self::assertSame(3, $this->storage->heldVariantsExist($upload->getPathPrefix()), 'the held copy is untouched');
    }
}
