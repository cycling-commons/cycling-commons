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
use App\Media\MediaEscalationService;
use App\Media\MediaStorage;
use App\Media\MediaTakedownCategory;
use App\Media\MediaTakedownService;
use App\Media\ProcessedPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The three photo desks page, and each one's count matches the list it pages
 * (docs/specs/photo-uploads.md §6b–§6d).
 *
 * The withheld-photo recovery page is the reason this file exists. It is the
 * surface an operator opens to undo a co-ordinated report flood, so a flood is
 * exactly the input it is guaranteed to meet — rendering every withheld photo
 * at once is the one failure mode the page cannot afford.
 */
final class MediaDeskPagingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private MediaTakedownService $takedowns;
    private MediaEscalationService $escalations;
    private MediaStorage $storage;
    private int $seq = 0;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->takedowns = static::getContainer()->get(MediaTakedownService::class);
        $this->escalations = static::getContainer()->get(MediaEscalationService::class);
        $this->storage = static::getContainer()->get(MediaStorage::class);
    }

    private function rider(string $email): User
    {
        $user = (new User())->setEmail($email)->setDisplayName('Desk Paging Rider');
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** An approved photo on an item, exactly as approval leaves it. */
    private function approved(User $owner): MediaUpload
    {
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);

        $item = (new Item())->setLetter('A')->setName('Fontaine '.++$this->seq)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('desk-paging-'.$this->seq)
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $this->em->persist($item);
        $this->em->flush();

        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, shard: 'EU-01');
        $this->em->persist($upload);
        $this->em->persist(new MediaModerationEvent($upload->getId(), (int) $owner->getId(), MediaAction::Uploaded));
        $upload->approve($item->getId());
        $this->em->flush();

        $this->storage->store('EU-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));
        $item->setAttributes(['photos' => [static::getContainer()->get(MediaDecisionService::class)->describe($upload)]]);
        $this->em->flush();

        return $upload;
    }

    public function testTheTakedownDeskPagesAndCountsTheSameSet(): void
    {
        $owner = $this->rider('desk-paging-takedown@example.com');
        for ($i = 0; $i < 3; ++$i) {
            $this->takedowns->request($this->approved($owner), 'Please remove it.');
        }

        self::assertSame(3, $this->takedowns->pendingCount());
        self::assertCount(2, $this->takedowns->pendingCards(1, 2));
        self::assertCount(1, $this->takedowns->pendingCards(2, 2));
        // Oldest first across the boundary — a rights request waits for
        // nobody's convenience, and paging must not reshuffle the queue.
        self::assertCount(3, array_unique([
            ...array_column($this->takedowns->pendingCards(1, 2), 'uuid'),
            ...array_column($this->takedowns->pendingCards(2, 2), 'uuid'),
        ]));
    }

    public function testTheRecoveryDeskPagesAFlood(): void
    {
        $owner = $this->rider('desk-paging-flood@example.com');
        for ($i = 0; $i < 3; ++$i) {
            // An urgent third-party report withholds on the spot — which is
            // what fills this page, three at a time here and by the hundred
            // in the incident it exists for.
            $this->takedowns->report(
                $this->approved($owner),
                MediaTakedownCategory::IntimateOrChild,
                'Vandalism.',
                null,
                '203.0.113.'.(40 + $i),
            );
        }

        self::assertSame(3, $this->takedowns->withheldThirdPartyCount());
        self::assertCount(2, $this->takedowns->withheldThirdPartyCards(1, 2));
        self::assertCount(1, $this->takedowns->withheldThirdPartyCards(2, 2));
    }

    public function testTheLegalHoldListPagesAndCountsTheSameSet(): void
    {
        $owner = $this->rider('desk-paging-held@example.com');
        $curator = $this->rider('desk-paging-curator@example.com');
        for ($i = 0; $i < 3; ++$i) {
            $this->escalations->escalate($this->approved($owner), $curator, 'Referred to the authority.');
        }

        self::assertSame(3, $this->escalations->heldCount());
        self::assertCount(2, $this->escalations->held(1, 2));
        self::assertCount(1, $this->escalations->held(2, 2));
    }

    /**
     * A photo under legal hold leaves the curator desk entirely, so the
     * takedown pager's count must drop with it — the list and the count read
     * one shared predicate, and this is what proves they still do.
     */
    public function testEscalatingRemovesAPhotoFromTheTakedownCountToo(): void
    {
        $owner = $this->rider('desk-paging-both@example.com');
        $curator = $this->rider('desk-paging-both-curator@example.com');
        $upload = $this->approved($owner);
        $this->takedowns->request($upload, 'Please remove it.');
        self::assertSame(1, $this->takedowns->pendingCount());

        $this->escalations->escalate($upload, $curator, 'Referred to the authority.');

        self::assertSame(0, $this->takedowns->pendingCount());
        self::assertCount(0, $this->takedowns->pendingCards());
        self::assertSame(1, $this->escalations->heldCount());
    }
}
