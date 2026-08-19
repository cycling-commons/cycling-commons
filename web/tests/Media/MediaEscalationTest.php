<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaConsent;
use App\Media\MediaDecisionService;
use App\Media\MediaDisposalService;
use App\Media\MediaEscalationService;
use App\Media\MediaStorage;
use App\Media\MediaTakedownCategory;
use App\Media\MediaTakedownService;
use App\Media\ProcessedPhoto;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;
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

        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, shard: 'EU-01');
        $this->em->persist($upload);
        $upload->approve($item->getId());
        $this->em->flush();

        $this->storage->store('EU-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));
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
        // …and still stored, which is the whole point.
        self::assertTrue($this->filesystem->fileExists($upload->getPathPrefix().'/sm.webp'));

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
            self::assertTrue($this->filesystem->fileExists($prefix.'/'.$variant.'.webp'), "{$variant} survives");
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

    public function testReleasingLiftsTheHoldWithoutRepublishingOrDeleting(): void
    {
        $curator = $this->rider('escalate-release@example.com');
        $admin = $this->rider('escalate-release-admin@example.com');
        $upload = $this->approved($this->rider('escalate-release-owner@example.com'));

        $this->escalations->escalate($upload, $curator, 'Not sure, escalating.');
        self::assertTrue($this->escalations->release($upload, $admin, 'False alarm.'));

        self::assertFalse($upload->isEscalated());
        // Deliberately NOT back on the map: it was the desk's decision before
        // the hold and it is the desk's decision again.
        self::assertArrayNotHasKey('photos', $this->itemOf($upload)->getAttributes());
        self::assertTrue($this->filesystem->fileExists($upload->getPathPrefix().'/sm.webp'));
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
}
