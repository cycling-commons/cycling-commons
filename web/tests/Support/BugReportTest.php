<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Security\FormGuard;
use App\Support\BugArea;
use App\Support\BugSeverity;
use App\Support\BugStatus;
use App\Support\Entity\BugReport;
use App\Support\SupportIntake;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * "Something is broken", from anybody (docs/specs/contact-and-support.md §5).
 *
 * The properties that make this worth having rather than a GitHub link:
 *
 * * **No account is needed.** The rider whose sign-up is broken cannot sign in
 *   to say that sign-up is broken. This is the single most important assertion
 *   in the file.
 * * **The context is captured, and only the path of it.** `location.href` on
 *   this site carries search terms and bounding boxes; a support table is not
 *   the place for a second copy of somebody's search history.
 * * **Publishing is a curator's decision.** Nothing reaches the public
 *   known-issues list because its author wrote it.
 * * **The outcome mail goes once.** A curator flipping a status twice must not
 *   mail the reporter twice.
 */
final class BugReportTest extends WebTestCase
{
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function solve(string $challenge, int $difficulty): string
    {
        for ($nonce = 0;; ++$nonce) {
            $digest = hash('sha256', $challenge.'.'.$nonce, true);
            $bits = 0;
            foreach (str_split($digest) as $byte) {
                $value = \ord($byte);
                for ($mask = 0x80; $mask > 0; $mask >>= 1) {
                    if (0 !== ($value & $mask)) {
                        break 2;
                    }
                    if (++$bits >= $difficulty) {
                        return (string) $nonce;
                    }
                }
            }
        }
    }

    /**
     * A challenge, from the endpoint the browser asks, not from the page.
     *
     * The form no longer carries one: a challenge is single use, so one baked
     * into the markup could not sit in a page a cache may hold
     * (docs/specs/page-caching.md §3.1). The script fetches it when somebody
     * starts typing, and so does this.
     *
     * @return array{0: string, 1: int} the challenge and its difficulty
     */
    private function fetchChallenge(KernelBrowser $client): array
    {
        $client->request('GET', '/form-challenge', server: ['HTTP_ACCEPT' => 'application/json']);
        $out = (array) json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertTrue($out['ok'] ?? false, 'the challenge endpoint must answer');

        return [(string) $out['challenge'], (int) $out['difficulty']];
    }

    private function file(KernelBrowser $client, array $overrides = [], array $server = []): Crawler
    {
        $page = $client->request('GET', '/report-bug');
        self::assertResponseIsSuccessful();

        $fields = [];
        foreach ($page->filter('#cc-guarded-form input[type="hidden"]') as $node) {
            $fields[$node->getAttribute('name')] = $node->getAttribute('value');
        }
        [$challenge, $difficulty] = $this->fetchChallenge($client);
        $fields['pow_challenge'] = $challenge;
        $fields['pow_nonce'] = $this->solve($challenge, $difficulty);

        $guard = static::getContainer()->get(FormGuard::class);
        $fields[FormGuard::STAMP] = $guard->stamp(new \DateTimeImmutable('-30 seconds'));

        $fields += [
            'title' => 'The map will not load',
            'body' => 'I opened the map and it stayed grey.',
            'steps' => '',
            'severity' => 'major',
            'area' => 'map',
            // Required without an account since 2026-08-28. A report nobody can
            // be answered about is a dead end for the person who filed it.
            'email' => 'reporter@cyclingcommons.org',
            FormGuard::HONEYPOT_A => '',
            FormGuard::HONEYPOT_B => '',
        ];

        return $client->request('POST', '/report-bug', $overrides + $fields, server: $server);
    }

    /** @return list<BugReport> */
    private function reports(): array
    {
        return $this->em()->getRepository(BugReport::class)->findBy([], ['id' => 'ASC']);
    }

    private function curator(string $email = 'curator@cyclingcommons.org'): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Bug Desk Curator');
        $user->setRoles(['ROLE_CURATOR']);
        // Elevated roles must be TOTP-enrolled or TwoFactorSetupEnforcer
        // redirects every curator page to /2fa/setup
        // (docs/specs/account-and-auth.md). Same fixture the other desk tests
        // use.
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /**
     * Read the CSRF token out of the rendered desk form.
     *
     * Generating it from the container would need a session that does not
     * exist outside a request, and would test a token the page never showed.
     */
    private function deskToken(KernelBrowser $client, int $id): string
    {
        $page = $client->request('GET', '/moderate/bugs/'.$id);
        self::assertResponseIsSuccessful();

        return (string) $page->filter('form.decide input[name="_token"]')->attr('value');
    }

    // -- filing ----------------------------------------------------------

    public function testAnyoneCanFileWithoutAnAccount(): void
    {
        $client = $this->client();
        $this->file($client);

        self::assertResponseIsSuccessful();

        $rows = $this->reports();
        self::assertCount(1, $rows);
        // No ACCOUNT, which is the point of this test. An address is still
        // needed, so that we can answer.
        self::assertNull($rows[0]->getUserId());
        self::assertSame('reporter@cyclingcommons.org', $rows[0]->getReporterEmail());
        self::assertSame(BugStatus::New, $rows[0]->getStatus());
        self::assertSame(BugSeverity::Major, $rows[0]->getSeverity());
        self::assertSame(BugArea::Map, $rows[0]->getArea());
    }

    /**
     * Without an account, an address is REQUIRED.
     *
     * It used to be optional, and the thank-you page then had to hedge: "if you
     * gave us an address, we will tell you what happened". That sentence
     * existed only because the form declined to ask, and the one thing a
     * reporter wants is to hear back (owner, 2026-08-28).
     */
    public function testAnAnonymousReportNeedsAnAddress(): void
    {
        $client = $this->client();
        $this->file($client, ['email' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->reports());
    }

    /** A signed-in reporter is never asked: the account address is used. */
    public function testASignedInReporterNeedsNoAddressField(): void
    {
        $client = $this->client();
        $rider = (new User())->setEmail('quiet@cyclingcommons.org');
        $rider->setPassword('x');
        $rider->setDisplayName('Quiet Rider');
        $this->em()->persist($rider);
        $this->em()->flush();
        $client->loginUser($rider);

        $this->file($client, ['email' => '']);

        self::assertResponseIsSuccessful();
        self::assertSame('quiet@cyclingcommons.org', $this->reports()[0]->getReporterEmail());
        self::assertTrue($this->reports()[0]->isAnswerable());
    }

    public function testASignedInReporterGetsTheirAccountAndAddressAttached(): void
    {
        $client = $this->client();
        $rider = (new User())->setEmail('rider@cyclingcommons.org');
        $rider->setPassword('x');
        $rider->setDisplayName('Signed In Rider');
        $this->em()->persist($rider);
        $this->em()->flush();
        $client->loginUser($rider);

        $this->file($client);

        $row = $this->reports()[0];
        self::assertSame($rider->getId(), $row->getUserId());
        self::assertSame('rider@cyclingcommons.org', $row->getReporterEmail());
        self::assertTrue($row->isAnswerable());
    }

    public function testTheBrowserAndScreenAreKept(): void
    {
        $client = $this->client();
        $this->file($client, [
            'browser' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/605.1',
            'viewport' => '390x844 @3',
            'app_version' => 'abc1234',
        ]);

        $row = $this->reports()[0];
        self::assertStringContainsString('iPhone', (string) $row->getBrowser());
        self::assertSame('390x844 @3', $row->getViewport());
        self::assertSame('abc1234', $row->getAppVersion());
    }

    /**
     * The page URL is kept as a PATH, with the query string dropped. Our own
     * URLs carry search terms and bounding boxes.
     */
    public function testOnlyThePathOfTheReportedPageIsKept(): void
    {
        $client = $this->client();
        $this->file($client, ['page_url' => 'http://localhost/map?q=where-i-live&bbox=1,2,3,4']);

        self::assertSame('/map', $this->reports()[0]->getPageUrl());
    }

    public function testAPageUrlOnSomebodyElsesHostIsDropped(): void
    {
        $client = $this->client();
        $this->file($client, ['page_url' => 'https://someone-elses-site.example/page']);

        self::assertNull($this->reports()[0]->getPageUrl());
    }

    public function testABarePathIsAcceptedFromTheNoJavascriptLink(): void
    {
        $client = $this->client();
        $this->file($client, ['page_url' => '/regions/wallonia?tab=climbs']);

        self::assertSame('/regions/wallonia', $this->reports()[0]->getPageUrl());
    }

    public function testTheAreaIsGuessedFromTheLinkedPage(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/report-bug?on=/map/best-of');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('#b-area option[value="map"][selected]'));
    }

    public function testTheLocalePrefixIsNotMistakenForTheArea(): void
    {
        // /nl/map is the map, not "unsure".
        self::assertSame(BugArea::Map, BugArea::guessFromPath('/nl/map'));
        self::assertSame(BugArea::Account, BugArea::guessFromPath('/de/account/settings'));
        self::assertSame(BugArea::Unsure, BugArea::guessFromPath('/'));
    }

    /**
     * The plain page works with scripting off. That is the ONLY reason it
     * exists beside the floating panel, and for a while it was untrue: the
     * proof of work was mandatory, nothing solved it without JavaScript, and
     * the form refused exactly the visitor it was built for.
     *
     * A bug reporter whose premise is "something here is broken" must survive
     * the broken thing being our own JavaScript.
     */
    public function testAReportFilesWithNoJavascriptAtAll(): void
    {
        $client = $this->client();
        // No nonce, and no browser or viewport either: nothing computed them.
        $this->file($client, ['pow_nonce' => '', 'browser' => '', 'viewport' => '', 'app_version' => '']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->reports());
    }

    /**
     * A WRONG nonce is still a refusal. It is a failed attempt, not an absent
     * one, and treating the two alike would let anyone downgrade themselves on
     * purpose while looking like a solver.
     */
    public function testAWrongNonceIsStillRefused(): void
    {
        $client = $this->client();
        $this->file($client, ['pow_nonce' => '0']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->reports());
    }

    // -- refusals --------------------------------------------------------

    public function testATitlelessReportWritesNothing(): void
    {
        $client = $this->client();
        $this->file($client, ['title' => '  ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->reports());
    }

    public function testABodylessReportWritesNothing(): void
    {
        $client = $this->client();
        $this->file($client, ['body' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->reports());
    }

    public function testAFilledHoneypotWritesNothing(): void
    {
        $client = $this->client();
        $this->file($client, [FormGuard::HONEYPOT_B => 'http://spam.example']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->reports());
    }

    /**
     * An address is optional, but a WRONG one is refused: the reporter would
     * otherwise sit waiting for an answer that bounced.
     */
    public function testAnUnroutableAddressWritesNothing(): void
    {
        $client = $this->client();
        $this->file($client, ['email' => 'someone@definitely-not-real.invalid']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->reports());
    }

    /**
     * The DNS check has no timeout and is the one step that leaves the
     * process, so it runs BEHIND the limiter: a stalled resolver costs the
     * caller a token. With the budget already spent, an unroutable domain comes
     * back as 429, which it could not if DNS were asked first. The limiter is
     * swapped before the first request because the container's test pool is
     * reset between requests.
     */
    public function testTheRateLimitIsSpentBeforeDnsIsAsked(): void
    {
        $client = $this->client();

        $budget = new RateLimiterFactory(
            ['id' => 'bug_report_test', 'policy' => 'sliding_window', 'limit' => 1, 'interval' => '1 day'],
            new InMemoryStorage(),
        );
        static::getContainer()->set('limiter.bug_report', $budget);
        $guard = static::getContainer()->get(FormGuard::class);
        $key = $guard->key(Request::create('/report-bug', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']));
        self::assertTrue($budget->create($key)->consume()->isAccepted());

        $this->file($client, ['email' => 'someone@definitely-not-real.invalid']);

        self::assertResponseStatusCodeSame(429);
        self::assertSame([], $this->reports());
    }

    /** The floating panel asks for JSON and must get JSON, not a page. */
    public function testThePanelGetsJsonBack(): void
    {
        $client = $this->client();
        $this->file($client, [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        self::assertJson($client->getResponse()->getContent() ?: '');
        self::assertSame(['ok' => true], json_decode($client->getResponse()->getContent() ?: '', true));
    }

    public function testThePanelGetsJsonBackOnRefusalToo(): void
    {
        $client = $this->client();
        $this->file($client, ['title' => ''], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        $out = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertFalse($out['ok']);
        self::assertSame('support.bug.error.title', $out['error']);
    }

    // -- the public list -------------------------------------------------

    public function testNothingIsPublicUntilACuratorSaysSo(): void
    {
        $client = $this->client();
        $this->file($client, ['title' => 'A secret-sounding bug']);

        $page = $client->request('GET', '/known-issues');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('A secret-sounding bug', $page->html());
    }

    public function testAPublishedReportAppearsUnderItsPublicWording(): void
    {
        $client = $this->client();
        $report = new BugReport('Reporter wrote this angrily about Dave', 'body');
        $report->setPublicTitle('The map is grey on first load');
        $report->setPublic(true);
        $report->setStatus(BugStatus::Planned);
        $this->em()->persist($report);
        $this->em()->flush();

        $html = $client->request('GET', '/known-issues')->html();

        self::assertStringContainsString('The map is grey on first load', $html);
        self::assertStringNotContainsString('Dave', $html);
    }

    /**
     * The column beside the form lists the newest public open entries, under
     * their public wording, and never a report a curator has not published.
     */
    public function testTheFormListsRecentKnownIssuesBesideIt(): void
    {
        $client = $this->client();
        $public = new BugReport('Reporter wrote this about Dave', 'body');
        $public->setPublicTitle('Region page loads slowly');
        $public->setPublic(true);
        $this->em()->persist($public);
        $this->em()->persist(new BugReport('An unpublished private report', 'body'));
        $this->em()->flush();

        $page = $client->request('GET', '/report-bug');
        self::assertResponseIsSuccessful();
        $aside = $page->filter('aside.bugrecent')->text();
        self::assertStringContainsString('Region page loads slowly', $aside);
        self::assertStringNotContainsString('Dave', $aside);
        self::assertStringNotContainsString('An unpublished private report', $aside);
        self::assertCount(1, $page->filter('aside.bugrecent a[href$="#issue-'.$public->getId().'"]'));
    }

    /**
     * "We are not fixing this" is a conversation with the reporter, not a
     * public notice.
     */
    public function testADeclinedReportStaysOffTheList(): void
    {
        $client = $this->client();
        $report = new BugReport('Declined thing', 'body');
        $report->setPublic(true);
        $report->setStatus(BugStatus::Declined);
        $this->em()->persist($report);
        $this->em()->flush();

        self::assertStringNotContainsString('Declined thing', $client->request('GET', '/known-issues')->html());
    }

    /**
     * A fix leaves the open list and lands on the fixed one.
     *
     * Owner 2026-09-13, in two steps. First: an entry opens by describing a
     * fault, and a reader scanning the list takes that fault for a live one
     * however the entry ends, so a launch list should carry only what is still
     * wrong. Then: the reason resolved issues used to stay was real, that
     * somebody who hits last week's bug should find it already answered rather
     * than file it again. So it moves rather than disappears.
     */
    public function testAResolvedReportMovesFromTheOpenListToTheFixedOne(): void
    {
        $client = $this->client();
        $report = new BugReport('Fixed thing', 'body');
        $report->setPublic(true);
        $report->setStatus(BugStatus::Resolved);
        $this->em()->persist($report);
        $this->em()->flush();

        self::assertStringNotContainsString('Fixed thing', $client->request('GET', '/known-issues')->html(),
            'the open list carries only what is still wrong');
        self::assertStringContainsString('Fixed thing', $client->request('GET', '/known-issues?show=fixed')->html(),
            'and the fixed list is where somebody who hit it looks');
    }

    /**
     * The Fixed tab dates the fix, not the last edit, and names the release
     * it shipped in, linked to that release on the changelog.
     */
    public function testAFixedEntryShowsItsFixDateAndRelease(): void
    {
        $client = $this->client();
        $report = new BugReport('Grey map', 'body');
        $report->setPublic(true);
        $report->setStatus(BugStatus::Resolved);
        $report->setFixRelease('v0.9.0');
        $this->em()->persist($report);
        $this->em()->flush();

        self::assertNotNull($report->getResolvedAt(), 'becoming resolved stamps the fix date');
        $page = $client->request('GET', '/known-issues?show=fixed');
        $row = $page->filter('#issue-'.$report->getId().' summary')->text();
        self::assertStringContainsString('Fixed on', $row);
        self::assertStringNotContainsString('Updated', $row);
        self::assertCount(1, $page->filter('#issue-'.$report->getId().' a.tag-rel[href$="/changelog#v0.9.0"]'));

        $report->setStatus(BugStatus::InProgress);
        self::assertNull($report->getResolvedAt(), 'reopening clears it');
    }

    public function testADeclinedReportIsOnNeitherList(): void
    {
        $client = $this->client();
        $report = new BugReport('Declined for good', 'body');
        $report->setPublic(true);
        $report->setStatus(BugStatus::Declined);
        $this->em()->persist($report);
        $this->em()->flush();

        self::assertStringNotContainsString('Declined for good', $client->request('GET', '/known-issues')->html());
        self::assertStringNotContainsString('Declined for good', $client->request('GET', '/known-issues?show=fixed')->html(),
            '"we are not fixing this" is a conversation with the reporter, not a public notice');
    }

    public function testAnOpenReportStaysOffTheFixedList(): void
    {
        $client = $this->client();
        $report = new BugReport('Still broken', 'body');
        $report->setPublic(true);
        $report->setStatus(BugStatus::InProgress);
        $this->em()->persist($report);
        $this->em()->flush();

        self::assertStringContainsString('Still broken', $client->request('GET', '/known-issues')->html());
        self::assertStringNotContainsString('Still broken', $client->request('GET', '/known-issues?show=fixed')->html());
    }

    // -- the outcome mail ------------------------------------------------

    public function testTheReporterIsToldTheOutcomeExactlyOnce(): void
    {
        self::createClient();
        $intake = static::getContainer()->get(SupportIntake::class);

        $report = new BugReport('Something broke', 'body');
        $report->setReporterEmail('rider@cyclingcommons.org');
        $report->setOutcomeNote('Fixed in tonight\'s release.');
        $report->setStatus(BugStatus::Resolved);
        $this->em()->persist($report);
        $this->em()->flush();

        $intake->announceBugOutcome($report);
        $first = $report->getNotifiedAt();
        self::assertNotNull($first);

        // A curator flipping the status again must not mail a second time.
        $intake->announceBugOutcome($report);
        self::assertSame($first, $report->getNotifiedAt());
    }

    public function testAnIntermediateStatusTellsNobody(): void
    {
        self::createClient();
        $intake = static::getContainer()->get(SupportIntake::class);

        $report = new BugReport('Something broke', 'body');
        $report->setReporterEmail('rider@cyclingcommons.org');
        $report->setStatus(BugStatus::InProgress);
        $this->em()->persist($report);
        $this->em()->flush();

        $intake->announceBugOutcome($report);

        self::assertNull($report->getNotifiedAt());
    }

    public function testAnAnonymousReportIsNeverMailedAnOutcome(): void
    {
        self::createClient();
        $intake = static::getContainer()->get(SupportIntake::class);

        $report = new BugReport('Something broke', 'body');
        $report->setStatus(BugStatus::Resolved);
        $report->setOutcomeNote('Fixed.');
        $this->em()->persist($report);
        $this->em()->flush();

        $intake->announceBugOutcome($report);

        self::assertNull($report->getNotifiedAt());
    }

    // -- the curator desk ------------------------------------------------

    public function testTheDeskIsCuratorsOnly(): void
    {
        $client = $this->client();
        $client->request('GET', '/moderate/bugs');

        self::assertResponseStatusCodeSame(302);
    }

    public function testACuratorSeesTheDesk(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $client->request('GET', '/moderate/bugs');

        self::assertResponseIsSuccessful();
    }

    /**
     * Resolved and Declined mail the reporter, so the desk refuses to reach
     * them without a reason to send.
     */
    public function testTheDeskRefusesAnOutcomeWithNoReason(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $report = new BugReport('Something broke', 'body');
        $this->em()->persist($report);
        $this->em()->flush();
        $id = (int) $report->getId();

        $client->request('POST', '/moderate/bugs/'.$id.'/decide', [
            '_token' => $this->deskToken($client, $id),
            'status' => 'resolved',
            'outcome_note' => '',
        ]);

        $this->em()->clear();
        $fresh = $this->em()->find(BugReport::class, $id);
        self::assertNotNull($fresh);
        self::assertSame(BugStatus::New, $fresh->getStatus(), 'the status must not have moved');
    }

    public function testACuratorCanPublishAndResolveInOneSave(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $report = new BugReport('Something broke', 'body');
        $report->setReporterEmail('rider@cyclingcommons.org');
        $this->em()->persist($report);
        $this->em()->flush();
        $id = (int) $report->getId();

        $client->request('POST', '/moderate/bugs/'.$id.'/decide', [
            '_token' => $this->deskToken($client, $id),
            'status' => 'resolved',
            'severity' => 'critical',
            'area' => 'search',
            'outcome_note' => 'Fixed tonight, thank you.',
            'public_title' => 'Search returned nothing',
            'is_public' => '1',
        ]);

        $this->em()->clear();
        $fresh = $this->em()->find(BugReport::class, $id);
        self::assertNotNull($fresh);
        self::assertSame(BugStatus::Resolved, $fresh->getStatus());
        self::assertSame(BugSeverity::Critical, $fresh->getSeverity());
        self::assertSame(BugArea::Search, $fresh->getArea());
        self::assertTrue($fresh->isPublic());
        self::assertSame('Search returned nothing', $fresh->getPublicTitle());
        self::assertNotNull($fresh->getNotifiedAt());
    }

    /** A rider sees their own reports and nobody else's. */
    public function testMyReportsShowsOnlyMine(): void
    {
        $client = $this->client();
        $mine = (new User())->setEmail('mine@cyclingcommons.org');
        $mine->setPassword('x');
        $mine->setDisplayName('Mine');
        $theirs = (new User())->setEmail('theirs@cyclingcommons.org');
        $theirs->setPassword('x');
        $theirs->setDisplayName('Theirs');
        $this->em()->persist($mine);
        $this->em()->persist($theirs);
        $this->em()->flush();

        $a = new BugReport('My own report', 'body');
        $a->setUserId((int) $mine->getId());
        $b = new BugReport('Somebody elses report', 'body');
        $b->setUserId((int) $theirs->getId());
        $this->em()->persist($a);
        $this->em()->persist($b);
        $this->em()->flush();

        $client->loginUser($mine);
        $html = $client->request('GET', '/account/reports')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('My own report', $html);
        self::assertStringNotContainsString('Somebody elses report', $html);
    }
}
