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
use App\Media\UrgentWithholdBreaker;
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
        $this->filesystem = static::getContainer()->get('media.storage.eu01');
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

        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, shard: 'EU-01');
        $this->em->persist($upload);
        $this->em->persist(new MediaModerationEvent($upload->getId(), (int) $owner->getId(), MediaAction::Uploaded));
        $upload->approve($item->getId());
        $this->em->flush();

        $this->storage->store('EU-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));

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

    /**
     * Oldest first — and the ORDER BY is the point.
     *
     * Two callers assert the SEQUENCE of what the contributor was told
     * ("hidden", then "given back"), which an unordered findBy() answers with
     * whatever order Postgres happens to hand back. It agreed with the
     * assertion for months and then stopped, under a full-suite run, for a
     * change that touches neither messages nor their writes — the sibling
     * helper above already sorts by id for exactly this reason.
     *
     * @return list<UserMessage>
     */
    private function messagesFor(User $user): array
    {
        return $this->em->getRepository(UserMessage::class)
            ->findBy(['userId' => (int) $user->getId()], ['id' => 'ASC']);
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

    /**
     * A contributor whose photo vanishes with no word from us reasonably
     * concludes we deleted their work (docs/specs/photo-uploads.md §6c). Both
     * halves are asserted together because sending the first without the
     * second would be worse than sending neither.
     */
    public function testHidingAPhotoTellsItsContributorAndSoDoesGivingItBack(): void
    {
        $owner = $this->rider('report-notify@example.com');
        $curator = $this->rider('report-notify-curator@example.com');
        $upload = $this->approved($owner);

        $this->takedowns->report($upload, MediaTakedownCategory::IntimateOrChild, 'Vandalism.', null, '203.0.113.30');

        $messages = $this->messagesFor($owner);
        self::assertCount(1, $messages);
        self::assertSame(UserMessageKind::MediaHiddenPendingReview, $messages[0]->getKind());

        $this->takedowns->decline($upload, $curator, 'Nobody visible.');

        $messages = $this->messagesFor($owner);
        self::assertCount(2, $messages);
        self::assertSame(UserMessageKind::MediaRestoredAfterReview, $messages[1]->getKind());
    }

    /** A report that only queued changed nothing they could see, so it says nothing. */
    public function testAQueuedReportTellsTheContributorNothing(): void
    {
        $owner = $this->rider('report-quiet@example.com');
        $curator = $this->rider('report-quiet-curator@example.com');
        $upload = $this->approved($owner);

        $this->takedowns->report($upload, MediaTakedownCategory::IdentifiableSelf, 'That is me.', null, '203.0.113.31');
        self::assertSame([], $this->messagesFor($owner));

        $this->takedowns->decline($upload, $curator);
        self::assertSame([], $this->messagesFor($owner), 'still nothing — it never left the map');
    }

    public function testRestoringAnAbusiveHideTellsTheContributor(): void
    {
        $owner = $this->rider('report-notify-dismiss@example.com');
        $admin = $this->rider('report-notify-admin@example.com');
        $upload = $this->approved($owner);

        $this->takedowns->report($upload, MediaTakedownCategory::IntimateOrChild, 'Vandalism.', null, '203.0.113.32');
        $this->takedowns->dismissAsAbuse($upload, $admin, 'Co-ordinated flood.');

        $kinds = array_map(
            static fn (UserMessage $m): UserMessageKind => $m->getKind(),
            $this->messagesFor($owner),
        );
        self::assertSame([UserMessageKind::MediaHiddenPendingReview, UserMessageKind::MediaRestoredAfterReview], $kinds);
    }

    public function testIneligiblePhotosSwallowTheReportSilently(): void
    {
        $owner = $this->rider('report-ineligible@example.com');
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);
        $pending = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, shard: 'EU-01');
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

    /**
     * The circuit breaker (docs/specs/photo-uploads.md §6c). Per-IP limits
     * cannot bound a distributed attacker — every photo's uuid is in its
     * public image URL, so a proxy pool could otherwise withhold one photo per
     * IP per day across the whole corpus. The site-wide budget is what makes
     * the blast radius finite.
     */
    public function testTheBudgetBoundsHowManyPhotosAnyoneCanWithhold(): void
    {
        $owner = $this->rider('report-breaker@example.com');

        // The budget is spent through the breaker itself rather than by
        // looping a literal count, so this test asserts the behaviour and not
        // the current numbers — the runtime setting owns those
        // (system-configuration.md §2).
        $breaker = static::getContainer()->get(UrgentWithholdBreaker::class);
        $spent = 0;
        while ($breaker->allowWithhold()) {
            self::assertLessThan(2000, ++$spent, 'the hourly budget must be finite');
        }

        // The flag the desk banner reads, so a curator knows the removals are
        // theirs from here on.
        self::assertTrue($breaker->isOpen());

        $next = $this->approved($owner);
        $this->takedowns->report($next, MediaTakedownCategory::IntimateOrChild, 'One past the budget.', null, '198.51.100.250');

        // Still filed, still a curator's problem — but it took nothing down.
        self::assertTrue($next->isTakedownPending(), 'the report is not lost');
        self::assertFalse($next->isTakedownWithheld(), 'the breaker is open, so nothing is withheld');
        self::assertCount(1, $this->itemOf($next)->getAttributes()['photos'], 'the photo is still on the map');

        $events = $this->em->getRepository(MediaModerationEvent::class)->findBy(['mediaId' => $next->getId()], ['id' => 'ASC']);
        $report = array_values(array_filter($events, static fn (MediaModerationEvent $e): bool => MediaAction::ThirdPartyReported === $e->getAction()));
        self::assertCount(1, $report);
        self::assertStringContainsString('breaker open', (string) $report[0]->getNote());
    }

    public function testAnOrdinaryReportNeverSpendsTheUrgentBudget(): void
    {
        $owner = $this->rider('report-breaker-cheap@example.com');
        $breaker = static::getContainer()->get(UrgentWithholdBreaker::class);

        // Ten ordinary reports — more than the hourly urgent budget — must not
        // move the breaker, or the cheap categories could exhaust the
        // expensive one's budget for free.
        for ($i = 0; $i < 10; ++$i) {
            $this->takedowns->report($this->approved($owner), MediaTakedownCategory::IdentifiableSelf, "Ordinary {$i}.", null, '198.51.100.'.$i);
        }

        self::assertFalse($breaker->isOpen());

        $urgent = $this->approved($owner);
        $this->takedowns->report($urgent, MediaTakedownCategory::IntimateOrChild, 'A real one.', null, '198.51.100.99');
        self::assertTrue($urgent->isTakedownWithheld(), 'the urgent budget was never touched');
    }

    /**
     * The recovery half (docs/specs/photo-uploads.md §6c). The property that
     * matters most is the LAST one: dismissal must not close the category, or
     * clearing a flood would permanently immunise every attacked photo against
     * the next genuine report.
     */
    public function testDismissingAnAbusiveReportRestoresWithoutClosingTheDoor(): void
    {
        $owner = $this->rider('report-dismiss@example.com');
        $admin = $this->rider('report-admin@example.com');
        $upload = $this->approved($owner);
        $before = $this->itemOf($upload)->getAttributes()['photos'];

        $this->takedowns->report($upload, MediaTakedownCategory::IntimateOrChild, 'Vandalism.', null, '198.51.100.7');
        self::assertTrue($upload->isTakedownWithheld());

        self::assertTrue($this->takedowns->dismissAsAbuse($upload, $admin, 'Co-ordinated flood.'));

        self::assertFalse($upload->isTakedownPending());
        self::assertSame($before, $this->itemOf($upload)->getAttributes()['photos'], 'back on the map');
        self::assertTrue($this->filesystem->fileExists($upload->getPathPrefix().'/sm.webp'));
        self::assertContains(MediaAction::TakedownDismissedAsAbuse, $this->actions($upload));
        // Their photo did visibly disappear, so they were told that and told
        // when it came back — never who reported it, and never the operator's
        // note (see testRestoringAnAbusiveHideTellsTheContributor).
        self::assertCount(2, $this->messagesFor($owner));
        // THE point: a real report of the same kind must still be heard.
        self::assertFalse($upload->hasDecidedTakedown(MediaTakedownCategory::IntimateOrChild));
        $this->takedowns->report($upload, MediaTakedownCategory::IntimateOrChild, 'A real one, later.', null, '198.51.100.8');
        self::assertTrue($upload->isTakedownPending(), 'the door is still open');
    }

    public function testDismissalRefusesAnUploadersOwnRequestAndAnythingAlreadyDecided(): void
    {
        $owner = $this->rider('report-dismiss-guard@example.com');
        $admin = $this->rider('report-admin2@example.com');

        // An uploader's own request is not abuse to be swept — it is a rights
        // request, and only the desk decides it.
        $mine = $this->approved($owner);
        $this->takedowns->request($mine, 'That is me.');
        self::assertFalse($this->takedowns->dismissAsAbuse($mine, $admin));
        self::assertTrue($mine->isTakedownPending());

        // A curator deciding first outranks a bulk sweep.
        $decided = $this->approved($owner);
        $this->takedowns->report($decided, MediaTakedownCategory::IdentifiableSelf, 'Me.', null, '198.51.100.9');
        $this->takedowns->decline($decided, $admin);
        self::assertFalse($this->takedowns->dismissAsAbuse($decided, $admin));
    }

    public function testTheRestoreWorklistHoldsOnlyWithheldThirdPartyRequests(): void
    {
        $owner = $this->rider('report-worklist@example.com');

        $queued = $this->approved($owner);
        $this->takedowns->report($queued, MediaTakedownCategory::IdentifiableSelf, 'Queued, never withheld.', null, '198.51.100.20');

        $mine = $this->approved($owner);
        $this->takedowns->request($mine, 'My own.');

        $withheld = $this->approved($owner);
        $this->takedowns->report($withheld, MediaTakedownCategory::IntimateOrChild, 'Withheld.', null, '198.51.100.21');

        $cards = $this->takedowns->withheldThirdPartyCards();

        self::assertCount(1, $cards);
        self::assertSame($withheld->getId()->toRfc4122(), $cards[0]['uuid']);
        self::assertSame(MediaTakedownCategory::IntimateOrChild, $cards[0]['category']);
        // A short salted-hash prefix, never an address or a raw IP.
        self::assertSame(8, \strlen($cards[0]['reporter']));
        self::assertStringNotContainsString('198.51.100', $cards[0]['reporter']);
    }

    public function testTheUploaderRouteStampsItsSource(): void
    {
        $upload = $this->approved($this->rider('report-source@example.com'));

        $this->takedowns->request($upload, 'My own photo, my own face.');

        self::assertSame(MediaTakedownSource::Uploader, $upload->getTakedownSource());
        self::assertTrue($upload->isTakedownWithheld(), 'the uploader route still withholds on the spot');
    }
}
