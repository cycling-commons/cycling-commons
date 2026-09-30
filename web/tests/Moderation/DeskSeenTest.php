<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\CatalogFinding;
use App\Catalog\Entity\Item;
use App\Catalog\Entity\RecommendedRoute;
use App\Catalog\Entity\Region;
use App\Catalog\Entity\RouteSuggestion;
use App\Catalog\Entity\Submission;
use App\Catalog\FindingKind;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\RouteSuggestionReason;
use App\Catalog\SubmissionStatus;
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
use App\Moderation\DeskSeen;
use App\Moderation\SeenSubject;
use App\Support\BugArea;
use App\Support\BugStatus;
use App\Support\Entity\BugReport;
use App\Support\Entity\ContentReport;
use App\Support\ReportGround;
use App\Support\ReportStatus;
use App\Support\ReportTarget;
use App\Tests\Translation\FindsOrCreatesTranslationEntry;
use App\Translation\Entity\TranslationProposal;
use App\Translation\ProposalService;
use App\Translation\TranslationCaches;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;

/**
 * One mark for a desk row its reader has not opened: the unseen bar
 * (`.is-unseen`). A row loses it when its curator opens the item (its page
 * or edit form loads, the map drawer opens it, or they decide it), never
 * because a list loaded, and never for another curator.
 *
 * @see docs/specs/moderation-and-contribution.md §5.2f
 */
final class DeskSeenTest extends WebTestCase
{
    use FindsOrCreatesTranslationEntry;

    /** Every desk list a curator reads. */
    private const array LISTS = [
        '/moderate',
        '/moderate/submissions',
        '/moderate/submissions/history',
        '/moderate/routes',
        '/moderate/reports',
        '/moderate/bugs',
        '/moderate/translations',
        '/moderate/translations/history',
        '/moderate/translations/stale?locale=nl',
        '/moderate/data',
        '/moderate/takedowns',
        '/moderate/room',
    ];

    private int $seq = 0;

    public function testLoadingTheDeskListsMarksNothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $curator = $this->curator('lists');
        $work = $this->seedWork();
        $client->loginUser($curator);

        foreach (self::LISTS as $page) {
            $client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
        }
        $client->request('GET', '/moderate/routes?region='.$work['region']);
        self::assertResponseIsSuccessful();

        self::assertSame(0, $this->seenRows($curator), 'no list page marks anything opened');
    }

    public function testOpeningAReportMarksItForThatCuratorOnly(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $me = $this->curator('report-me');
        $other = $this->curator('report-other');
        $report = $this->report();
        $id = $report->getId()->toRfc4122();

        $client->loginUser($me);
        self::assertTrue($this->rowIsUnseen($client->request('GET', '/moderate/reports'), 'a[href$="/moderate/reports/'.$id.'"]', '.rrow'));
        $client->request('GET', '/moderate/reports/'.$id);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->rowIsUnseen($client->request('GET', '/moderate/reports'), 'a[href$="/moderate/reports/'.$id.'"]', '.rrow'), 'opened: the bar is gone');

        $client->loginUser($other);
        self::assertTrue($this->rowIsUnseen($client->request('GET', '/moderate/reports'), 'a[href$="/moderate/reports/'.$id.'"]', '.rrow'), 'another curator still has it unopened');
    }

    public function testOpeningAPendingSubmissionInTheDrawerMarksIt(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $curator = $this->curator('drawer');
        $sub = $this->submission('Drawer fountain');
        $client->loginUser($curator);

        $row = '.q-item[data-item-id="'.$sub->getId().'"]';
        self::assertTrue($this->rowIsUnseen($client->request('GET', '/moderate/submissions'), $row, $row));

        // The map hands a curator the token the drawer posts with.
        $map = $client->request('GET', '/map?pending='.$sub->getId());
        self::assertResponseIsSuccessful();
        self::assertSame(1, preg_match('/window\.CC_SEEN_TOKEN = "([^"]+)"/', (string) $client->getResponse()->getContent(), $m), 'the map carries the seen token');
        self::assertStringContainsString('"unseen":true', (string) $map->filter('script')->reduce(static fn (Crawler $s): bool => str_contains($s->text(), 'CC_PENDING'))->text(), 'the pending payload says the row is unopened');

        $client->request('POST', '/moderate/seen', ['_token' => 'forged', 'type' => 'submission', 'id' => (string) $sub->getId()]);
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/moderate/seen', ['_token' => $m[1], 'type' => 'bug_report', 'id' => '1']);
        self::assertResponseStatusCodeSame(400, 'only what the drawer opens');

        $client->request('POST', '/moderate/seen', ['_token' => $m[1], 'type' => 'submission', 'id' => (string) $sub->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(['seen' => true], json_decode((string) $client->getResponse()->getContent(), true));
        $client->request('POST', '/moderate/seen', ['_token' => $m[1], 'type' => 'submission', 'id' => (string) $sub->getId()]);
        self::assertSame(['seen' => false], json_decode((string) $client->getResponse()->getContent(), true), 'once');

        self::assertFalse($this->rowIsUnseen($client->request('GET', '/moderate/submissions'), $row, $row));
    }

    public function testTheQueueCardsEditLinkOpensTheSubmission(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $curator = $this->curator('edit-link');
        $item = $this->item('edit-link');
        $sub = $this->submission('Edited fountain', $item);
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/moderate/submissions');
        $href = (string) $crawler->filter('.q-item[data-item-id="'.$sub->getId().'"] a.q-title-link')->attr('href');
        self::assertStringContainsString('sub='.$sub->getId(), $href, 'the edit link names the submission');

        $client->request('GET', $href);
        self::assertResponseIsSuccessful();
        self::assertTrue(static::getContainer()->get(DeskSeen::class)->isSeen((int) $curator->getId(), SeenSubject::Submission, (int) $sub->getId()));
    }

    public function testOpenWorkCarriesTheBarUntilOpenedAndSettledWorkNever(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $curator = $this->curator('settled');
        $open = $this->report();
        $settled = $this->report();
        $settled->decide(ReportStatus::Rejected, 'Decided in the test.', 1, new \DateTimeImmutable());
        $this->em()->flush();
        $client->loginUser($curator);

        // Nothing is counted as opened up front: open work arrives with the bar.
        $crawler = $client->request('GET', '/moderate/reports?status=all');
        self::assertTrue($this->rowIsUnseen($crawler, $this->reportLink($open), '.rrow'), 'open and not opened: the bar');
        self::assertFalse($this->rowIsUnseen($crawler, $this->reportLink($settled), '.rrow'), 'settled: never the bar, opened or not');

        $client->request('GET', '/moderate/reports/'.$open->getId()->toRfc4122());
        self::assertFalse($this->rowIsUnseen($client->request('GET', '/moderate/reports?status=all'), $this->reportLink($open), '.rrow'), 'opened: gone');

        // Every list of settled work, none of it opened by this curator.
        $region = $this->region();
        $live = $this->route(ItemState::Unverified, $region);
        $history = $this->submission('Settled place', SubmissionStatus::Approved);
        $proposal = $this->proposal('settled', settled: true);
        $bug = $this->bug();
        $bug->setStatus(BugStatus::Resolved);
        $this->em()->flush();
        $answered = $this->takedown($this->rider());
        static::getContainer()->get(MediaTakedownService::class)->decline($answered, $this->curator('answerer'), 'Not a person.');
        foreach ([
            ['/moderate/submissions/history', '.q-item[data-submission-id="'.$history->getId().'"]', '.q-item'],
            ['/moderate/routes?region='.$region, '.q-item[data-item-id="r'.$live->getId().'"]', '.q-item'],
            ['/moderate/translations/history', 'a[href$="/moderate/translations/'.$proposal->getId().'"]', '.q-item'],
            ['/moderate/bugs?status=all', 'a.bugref[href$="/moderate/bugs/'.$bug->getId().'"]', '.bugrow'],
            ['/moderate/takedowns', '.q-item[data-media="'.$answered->getId()->toRfc4122().'"]', '.q-item'],
        ] as [$page, $row, $class]) {
            $crawler = $client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
            self::assertFalse($this->rowIsUnseen($crawler, $row, $class), $page.': settled work carries no bar');
        }
        self::assertSame(0, $this->seenRows($curator) - 1, 'only the one report page opened anything');
    }

    public function testADecisionTakesTheBarOffForEveryCurator(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $decider = $this->curator('decider');
        $colleague = $this->curator('colleague');
        $report = $this->report();

        $client->loginUser($colleague);
        self::assertTrue($this->rowIsUnseen($client->request('GET', '/moderate/reports'), $this->reportLink($report), '.rrow'), 'waiting, not opened by the colleague');

        $client->loginUser($decider);
        $page = $client->request('GET', '/moderate/reports/'.$report->getId()->toRfc4122());
        $token = (string) $page->filter('form.decide input[name="_token"]')->attr('value');
        $client->request('POST', '/moderate/reports/'.$report->getId()->toRfc4122().'/decide', ['_token' => $token, 'status' => 'rejected', 'note' => 'Nothing wrong with it.']);
        self::assertResponseRedirects();

        $client->loginUser($colleague);
        self::assertFalse($this->rowIsUnseen($client->request('GET', '/moderate/reports?status=all'), $this->reportLink($report), '.rrow'), 'dealt with: gone for the colleague too, who never opened it');
        self::assertSame(0, $this->seenRows($colleague));
    }

    public function testEveryDeskMarksAnUnopenedRowAndNotAnOpenedOne(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $curator = $this->curator('every');
        $me = (int) $curator->getId();
        $seen = static::getContainer()->get(DeskSeen::class);
        $client->loginUser($curator);

        // [page, row selector of the unopened one, of the opened one, the row's own class]
        $cases = [];

        [$a, $b] = [$this->submission('Unopened place'), $this->submission('Opened place')];
        $seen->mark($me, SeenSubject::Submission, (int) $b->getId());
        $cases[] = ['/moderate/submissions', '.q-item[data-item-id="'.$a->getId().'"]', '.q-item[data-item-id="'.$b->getId().'"]', '.q-item'];

        $region = $this->region();
        [$a, $b] = [$this->route(ItemState::Submitted, $region), $this->route(ItemState::Submitted, $region)];
        $seen->mark($me, SeenSubject::Route, (int) $b->getId());
        $cases[] = ['/moderate/routes', '.q-item[data-item-id="'.$a->getId().'"]', '.q-item[data-item-id="'.$b->getId().'"]', '.q-item'];

        $live = $this->route(ItemState::Unverified, $region);

        [$a, $b] = [$this->suggestion($live), $this->suggestion($live)];
        $seen->mark($me, SeenSubject::RouteSuggestion, (int) $b->getId());
        $cases[] = ['/moderate/routes', '.q-item[data-suggestion-id="'.$a->getId().'"]', '.q-item[data-suggestion-id="'.$b->getId().'"]', '.q-item'];

        [$a, $b] = [$this->report(), $this->report()];
        $seen->mark($me, SeenSubject::ContentReport, $b->getId()->toRfc4122());
        $cases[] = ['/moderate/reports', 'a[href$="/moderate/reports/'.$a->getId()->toRfc4122().'"]', 'a[href$="/moderate/reports/'.$b->getId()->toRfc4122().'"]', '.rrow'];

        [$a, $b] = [$this->bug(), $this->bug()];
        $seen->mark($me, SeenSubject::BugReport, (int) $b->getId());
        $cases[] = ['/moderate/bugs', 'a.bugref[href$="/moderate/bugs/'.$a->getId().'"]', 'a.bugref[href$="/moderate/bugs/'.$b->getId().'"]', '.bugrow'];

        [$a, $b] = [$this->proposal('open-a'), $this->proposal('open-b')];
        $seen->mark($me, SeenSubject::TranslationProposal, (int) $b->getId());
        $cases[] = ['/moderate/translations', 'a[href$="/moderate/translations/'.$a->getId().'"]', 'a[href$="/moderate/translations/'.$b->getId().'"]', '.q-item'];

        [$a, $b] = [$this->staleEntry('stale-a'), $this->staleEntry('stale-b')];
        $seen->mark($me, SeenSubject::TranslationStale, SeenSubject::staleId('nl', (int) $b->getId(), $b->getEnglishVersion()));
        $cases[] = ['/moderate/translations/stale?locale=nl', 'a[href$="/translate/'.$a->getId().'"]', 'a[href$="/translate/'.$b->getId().'"]', '.q-item'];

        [$a, $b] = [$this->finding(), $this->finding()];
        $seen->mark($me, SeenSubject::CatalogFinding, (int) $b->getId());
        $cases[] = ['/moderate/data', '.q-item[data-finding-id="'.$a->getId().'"]', '.q-item[data-finding-id="'.$b->getId().'"]', '.q-item'];

        $rider = $this->rider();
        [$a, $b] = [$this->takedown($rider), $this->takedown($rider)];
        $seen->mark($me, SeenSubject::Takedown, $b->getId()->toRfc4122());
        $cases[] = ['/moderate/takedowns', '.q-item[data-media="'.$a->getId()->toRfc4122().'"]', '.q-item[data-media="'.$b->getId()->toRfc4122().'"]', '.q-item'];

        foreach ($cases as [$page, $unopened, $opened, $row]) {
            $crawler = $client->request('GET', $page);
            self::assertResponseIsSuccessful($page);
            self::assertTrue($this->rowIsUnseen($crawler, $unopened, $row), $page.': the unopened row carries the bar');
            self::assertFalse($this->rowIsUnseen($crawler, $opened, $row), $page.': the opened row does not');
            self::assertSame('Not opened yet', trim($crawler->filter($row.'.is-unseen .unseen-note')->first()->text()), $page.': the bar says so in words');
        }
    }

    public function testTheDashboardMarksARoomPostNotOpenedYet(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $curator = $this->curator('dash');
        $colleague = $this->curator('dash-colleague');
        $room = static::getContainer()->get(\App\Messaging\CuratorRoom::class);
        $post = $room->post((int) $colleague->getId(), null, null, 'Worth a look.', title: 'Dashboard post '.bin2hex(random_bytes(3)));
        $client->loginUser($curator);

        $row = static fn (Crawler $page): Crawler => $page->filter('.db-row')->reduce(static fn (Crawler $r): bool => str_contains($r->text(), $post->getTitle()));
        $crawler = $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('is-unseen', (string) $row($crawler)->attr('class'));

        $room->markRead((int) $curator->getId(), (int) $post->getId());
        self::assertStringNotContainsString('is-unseen', (string) $row($client->request('GET', '/moderate'))->attr('class'));
    }

    public function testOpeningARoutesDeskPageOpensItsCorrectionsToo(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $curator = $this->curator('route-page');
        $route = $this->route(ItemState::Unverified, $this->region());
        $fix = $this->suggestion($route);
        $client->loginUser($curator);

        $client->request('GET', '/moderate/routes/'.$route->getId());
        self::assertResponseIsSuccessful();
        $seen = static::getContainer()->get(DeskSeen::class);
        self::assertTrue($seen->isSeen((int) $curator->getId(), SeenSubject::Route, (int) $route->getId()));
        self::assertTrue($seen->isSeen((int) $curator->getId(), SeenSubject::RouteSuggestion, (int) $fix->getId()));
    }

    public function testDecidingATakedownOpensIt(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $curator = $this->curator('takedown');
        $upload = $this->takedown($this->rider());
        $client->loginUser($curator);

        $crawler = $client->request('GET', '/moderate/takedowns');
        $token = (string) $crawler->filter('.q-item[data-media="'.$upload->getId()->toRfc4122().'"] form.td-actions input[name="_token"]')->attr('value');
        $client->request('POST', '/moderate/takedown', ['_token' => $token, 'media' => $upload->getId()->toRfc4122(), 'decision' => 'decline', 'note' => 'Not a person.']);
        self::assertResponseRedirects();

        self::assertTrue(static::getContainer()->get(DeskSeen::class)->isSeen((int) $curator->getId(), SeenSubject::Takedown, $upload->getId()->toRfc4122()));
    }

    public function testAnOutOfReachPageOpensNothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $curator = $this->curator('missing');
        $client->loginUser($curator);

        $client->request('GET', '/moderate/bugs/999999999');
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->seenRows($curator));
    }

    /** Whether the row holding `$inside` (or the row itself) carries the bar. */
    private function rowIsUnseen(Crawler $page, string $inside, string $row): bool
    {
        $node = $page->filter($inside);
        self::assertGreaterThan(0, $node->count(), 'the page lists '.$inside);
        $node = $node->first();
        $holder = $node->matches($row) ? $node : $node->closest($row);
        self::assertNotNull($holder, $inside.' sits in a '.$row);

        return \in_array('is-unseen', explode(' ', (string) $holder->attr('class')), true);
    }

    private function reportLink(ContentReport $report): string
    {
        return 'a[href$="/moderate/reports/'.$report->getId()->toRfc4122().'"]';
    }

    private function seenRows(User $user): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM moderation_seen WHERE user_id = :u', ['u' => $user->getId()]);
    }

    /** @return array{region: int} */
    private function seedWork(): array
    {
        $region = $this->region();
        $this->submission('Listed place');
        $this->submission('Listed settled', SubmissionStatus::Approved);
        $route = $this->route(ItemState::Unverified, $region);
        $this->route(ItemState::Submitted, $region);
        $this->suggestion($route);
        $this->report();
        $this->bug();
        $this->proposal('list-open');
        $this->proposal('list-settled', settled: true);
        $this->staleEntry('list');
        $this->finding();
        $this->takedown($this->rider());

        return ['region' => $region];
    }

    private function curator(string $tag): User
    {
        $u = (new User())->setEmail('seen-'.$tag.'-'.bin2hex(random_bytes(4)).'@example.test')->setDisplayName('Seen '.$tag);
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_CURATOR']);
        $u->setPassword('x');
        $u->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    private function rider(): User
    {
        $u = (new User())->setEmail('seen-rider-'.bin2hex(random_bytes(4)).'@example.test')->setDisplayName('Seen Rider');
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setPassword('x');
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    private function item(string $slug): Item
    {
        $item = (new Item())->setLetter('A')->setName('Seen place '.++$this->seq)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('seen-'.$slug.'-'.bin2hex(random_bytes(4)))
            ->setSource(ItemSource::User)->setState(ItemState::Verified)->setAttributes([]);
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    private function submission(string $title, SubmissionStatus|Item|null $statusOrItem = null): Submission
    {
        $rider = $this->rider();
        $sub = (new Submission())->setLetter('A')->setUserId((int) $rider->getId())
            ->setTitle($title.' '.bin2hex(random_bytes(3)))->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        if ($statusOrItem instanceof Item) {
            $sub->setType(SubmissionType::Edit)->setItemId((int) $statusOrItem->getId());
        } else {
            $sub->setType(SubmissionType::NewItem);
        }
        if ($statusOrItem instanceof SubmissionStatus) {
            $sub->setStatus($statusOrItem);
            $sub->setDecidedAt(new \DateTimeImmutable());
        }
        $this->em()->persist($sub);
        $this->em()->flush();

        return $sub;
    }

    private function region(): int
    {
        $r = (new Region())->setSlug('seen-'.bin2hex(random_bytes(4)))->setName('Seen county '.++$this->seq)->setCountryCode('BE');
        $this->em()->persist($r);
        $this->em()->flush();

        return (int) $r->getId();
    }

    private function route(ItemState $state, int $region): RecommendedRoute
    {
        $r = (new RecommendedRoute())->setName('Seen loop '.++$this->seq)
            ->setGeom('{"type":"LineString","coordinates":[[5.2,50.4],[5.3,50.5]]}')
            ->setDistanceM(20000)->setState($state)->setRegionId($region)
            ->setSource(ItemSource::User)->setSourceRef('user:seen-'.bin2hex(random_bytes(4)))->setProposedBy((int) $this->rider()->getId());
        $this->em()->persist($r);
        $this->em()->flush();

        return $r;
    }

    private function suggestion(RecommendedRoute $route): RouteSuggestion
    {
        $s = new RouteSuggestion((int) $route->getId(), (int) $this->rider()->getId(), RouteSuggestionReason::BrokenTrack, 'Gate '.++$this->seq);
        $this->em()->persist($s);
        $this->em()->flush();

        return $s;
    }

    private function report(): ContentReport
    {
        $report = new ContentReport(Uuid::v4(), ReportTarget::Item, (string) ++$this->seq, ReportGround::Untrue, 'Seen reason', str_repeat('a', 64), new \DateTimeImmutable());
        $this->em()->persist($report);
        $this->em()->flush();

        return $report;
    }

    private function bug(): BugReport
    {
        $bug = new BugReport('Seen bug '.++$this->seq, 'Body');
        $bug->setStatus(BugStatus::New);
        $bug->setArea(BugArea::Map);
        $this->em()->persist($bug);
        $this->em()->flush();

        return $bug;
    }

    private function proposal(string $tag, bool $settled = false): TranslationProposal
    {
        $entry = $this->findOrCreateEntry($this->em(), 'seen.test.'.$tag.'.'.bin2hex(random_bytes(3)), 'Seen wording '.$tag);
        $proposal = static::getContainer()->get(ProposalService::class)->submit($this->rider(), $entry, 'nl', 'Gezien '.$tag, true);
        if ($settled) {
            $this->db()->executeStatement("UPDATE translation_proposal SET status = 'rejected', decided_at = now() WHERE id = :id", ['id' => $proposal->getId()]);
        }

        return $proposal;
    }

    private function staleEntry(string $tag): \App\Translation\Entity\TranslationEntry
    {
        $entry = $this->findOrCreateEntry($this->em(), 'seen.stale.'.$tag.'.'.bin2hex(random_bytes(3)), 'Old wording');
        $entry->applyApprovedEnglish('New wording');
        $this->em()->flush();
        static::getContainer()->get(TranslationCaches::class)->invalidateAll();

        return $entry;
    }

    private function finding(): CatalogFinding
    {
        $finding = new CatalogFinding(FindingKind::Duplicate, $this->item('finding'), ['distanceM' => 120.0, 'osmName' => 'Seen']);
        $finding->setRelatedItem($this->item('finding-rel'));
        $this->em()->persist($finding);
        $this->em()->flush();

        return $finding;
    }

    /** An approved photo on an item with a removal request waiting. */
    private function takedown(User $owner): MediaUpload
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
        static::getContainer()->get(MediaTakedownService::class)->request($upload, 'Please remove it.');

        return $upload;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
