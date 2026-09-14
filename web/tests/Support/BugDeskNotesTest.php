<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use App\Support\BugSeverity;
use App\Support\BugSort;
use App\Support\BugStatus;
use App\Support\Entity\BugReport;
use App\Support\Entity\ReleaseTag;
use App\Support\GitHubIssues;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The curator-only note, the release tag, and the desk's ordering
 * (docs/specs/contact-and-support.md §9).
 *
 * The first three tests are the whole reason `internal_note` is a separate
 * column rather than more text in the outcome note. The outcome note is
 * written FOR the reporter: it is mailed to them and, once published, it is the
 * text on `/known-issues`. A curator who wants to write "same root cause as #7"
 * has nowhere else to put it, so they either say nothing or say it in the place
 * that gets sent to a stranger.
 */
final class BugDeskNotesTest extends WebTestCase
{
    private const string SECRET = 'Same root cause as report seven, Dave broke it';

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

    private int $curatorId = 0;

    private function curator(): User
    {
        $user = (new User())->setEmail('notes-desk@cyclingcommons.org');
        $user->setPassword('x');
        $user->setDisplayName('Notes Desk Curator');
        $user->setRoles(['ROLE_CURATOR']);
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function bug(
        string $title = 'The map will not load',
        ?BugSeverity $severity = null,
        ?BugStatus $status = null,
        ?string $email = null,
    ): BugReport {
        $report = new BugReport($title, 'It stayed grey.');
        if (null !== $severity) {
            $report->setSeverity($severity);
        }
        if (null !== $status) {
            $report->setStatus($status);
        }
        if (null !== $email) {
            $report->setReporterEmail($email);
        }
        $this->em()->persist($report);
        $this->em()->flush();

        return $report;
    }

    private function decide(KernelBrowser $client, BugReport $report, array $fields = []): void
    {
        $page = $client->request('GET', '/moderate/bugs/'.$report->getId());
        self::assertResponseIsSuccessful();

        $client->request('POST', '/moderate/bugs/'.$report->getId().'/decide', $fields + [
            '_token' => (string) $page->filter('form.decide input[name="_token"]')->attr('value'),
            'status' => 'resolved',
            'severity' => $report->getSeverity()->value,
            'area' => $report->getArea()->value,
            'outcome_note' => 'Fixed. Thank you for the report.',
        ]);
    }

    public function testFixedInTakesOnlyAReleaseAnAdminRecorded(): void
    {
        // Owner 2026-09-08: a dropdown of release tags, kept in the admin.
        $client = $this->client();
        $report = $this->bug();
        $client->loginUser($this->curator());
        $tag = (new ReleaseTag())->setTag('v9.9.9-test');
        $this->em()->persist($tag);
        $this->em()->flush();

        $page = $client->request('GET', '/moderate/bugs/'.$report->getId());
        self::assertCount(1, $page->filter('select[name="fix_release"] option[value="v9.9.9-test"]'), 'the recorded tag is offered');
        self::assertCount(1, $page->filter('select[name="fix_release"] option[value="v0.8.0-beta"]'), 'and so is the seeded first release');

        $this->decide($client, $report, ['fix_release' => 'v9.9.9-test']);
        $this->em()->clear();
        self::assertSame('v9.9.9-test', $this->em()->find(BugReport::class, $report->getId())?->getFixRelease());

        $this->decide($client, $report, ['fix_release' => 'v1.2.3-typed']);
        $this->em()->clear();
        self::assertSame('', (string) $this->em()->find(BugReport::class, $report->getId())?->getFixRelease(), 'a tag nobody recorded is not stored');
    }

    public function testOnlyAnAdminMayOpenAPublicBugOnGithub(): void
    {
        // Owner 2026-09-08: "only admins are allowed to put site issues to github".
        $client = $this->client();
        static::getContainer()->set(GitHubIssues::class, new GitHubIssues(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('{"number":77}', ['http_code' => 201])),
            'cycling/commons', 'k-1', 'https://example.test',
        ));
        $report = $this->bug();
        $report->setPublic(true);
        $report->setPublicTitle('A public title');
        $this->em()->flush();

        $curator = $this->curator();
        $this->curatorId = (int) $curator->getId();
        $client->loginUser($curator);
        $page = $client->request('GET', '/moderate/bugs/'.$report->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('form.github-open'), 'a curator sees no button');
        $client->request('POST', '/moderate/bugs/'.$report->getId().'/github', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403, 'and cannot post to the route either');

        // The same person, promoted: one account, no second row with the same address.
        $admin = $this->em()->find(User::class, $this->curatorId);
        self::assertInstanceOf(User::class, $admin);
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em()->flush();
        $client->loginUser($admin);
        $page = $client->request('GET', '/moderate/bugs/'.$report->getId());
        $form = $page->filter('form.github-open');
        self::assertCount(1, $form, 'an admin sees the button');
        $client->submit($form->form());
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertStringContainsString('#77', (string) $client->getResponse()->getContent());
        $this->em()->clear();
        self::assertSame(77, $this->em()->find(BugReport::class, $report->getId())?->getGithubIssue());
    }

    // -- the internal note stays internal ---------------------------------

    public function testTheInternalNoteIsSavedAndShownToACurator(): void
    {
        $client = $this->client();
        $report = $this->bug();
        $client->loginUser($this->curator());

        $this->decide($client, $report, ['internal_note' => self::SECRET]);

        $this->em()->clear();
        $saved = $this->em()->find(BugReport::class, $report->getId());
        self::assertSame(self::SECRET, $saved?->getInternalNote());

        $page = $client->request('GET', '/moderate/bugs/'.$report->getId());
        self::assertStringContainsString('Dave broke it', $page->text());
    }

    /** The single most important assertion in this file. */
    public function testTheInternalNoteIsNeverMailedToTheReporter(): void
    {
        $client = $this->client();
        $report = $this->bug(email: 'reporter@cyclingcommons.org');
        $client->loginUser($this->curator());

        $this->decide($client, $report, ['internal_note' => self::SECRET]);

        // Resolved mails the reporter, so there IS a mail to check.
        self::assertEmailCount(1, null, 'the outcome mail');
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertStringNotContainsString('Dave', (string) $mail->getHtmlBody());
        self::assertStringNotContainsString('root cause', (string) $mail->getHtmlBody());
        self::assertStringContainsString('Thank you for the report', (string) $mail->getHtmlBody());
    }

    public function testTheInternalNoteNeverReachesThePublicList(): void
    {
        $client = $this->client();
        $report = $this->bug();
        $client->loginUser($this->curator());

        $this->decide($client, $report, [
            'internal_note' => self::SECRET,
            'is_public' => '1',
            'public_title' => 'The map is grey on first load',
            // Not the helper's `resolved`: a resolved report leaves the public
            // list entirely, and this test is about what a listed entry says,
            // not about which entries are listed.
            'status' => 'in_progress',
        ]);

        // Drop the session rather than hitting /logout, which is POST-only
        // (enable_csrf), and reuse the client: the kernel boots once per test.
        $client->getCookieJar()->clear();
        $public = $client->request('GET', '/known-issues')->text();

        self::assertStringContainsString('The map is grey on first load', $public);
        self::assertStringNotContainsString('Dave', $public);
        self::assertStringNotContainsString('root cause', $public);
    }

    public function testAnEmptyInternalNoteIsStoredAsNothing(): void
    {
        $client = $this->client();
        $report = $this->bug();
        $client->loginUser($this->curator());

        $this->decide($client, $report, ['internal_note' => "   \n  "]);

        $this->em()->clear();
        self::assertNull($this->em()->find(BugReport::class, $report->getId())?->getInternalNote());
    }

    // -- the release tag ---------------------------------------------------

    public function testTheReleaseTagIsSavedAndShownOnTheRow(): void
    {
        $client = $this->client();
        $report = $this->bug();
        $client->loginUser($this->curator());
        // Recorded first: the desk offers only what an admin recorded (2026-09-08).
        $this->em()->persist((new ReleaseTag())->setTag('v0.9.0'));
        $this->em()->flush();

        $this->decide($client, $report, ['fix_release' => 'v0.9.0']);

        $this->em()->clear();
        self::assertSame('v0.9.0', $this->em()->find(BugReport::class, $report->getId())?->getFixRelease());

        $list = $client->request('GET', '/moderate/bugs?status=resolved')->text();
        self::assertStringContainsString('v0.9.0', $list);
    }

    /**
     * A tag is not validated against a version pattern.
     *
     * This project has shipped `v0.8.0-beta`, and a desk that rejects the tag a
     * curator is looking at gets a wrong tag typed into it instead.
     */
    public function testAnUnusualTagIsAccepted(): void
    {
        $report = $this->bug();
        $report->setFixRelease('2026.08-hotfix.2');

        self::assertSame('2026.08-hotfix.2', $report->getFixRelease());
    }

    public function testATagLongerThanTheColumnIsCut(): void
    {
        $report = $this->bug();
        $report->setFixRelease(str_repeat('v', 200));

        self::assertSame(64, mb_strlen((string) $report->getFixRelease()));
    }

    // -- ordering ----------------------------------------------------------

    public function testTheDeskDefaultsToNewestFirst(): void
    {
        self::bootKernel();

        self::assertSame(BugSort::Newest, BugSort::fromInput(''));
        self::assertSame(BugSort::Newest, BugSort::fromInput('nonsense'));
        self::assertFalse(BugSort::Newest->bySeverity());
    }

    public function testHighPriorityFirstPutsCriticalAtTheTop(): void
    {
        $client = $this->client();
        $this->bug('A cosmetic thing', BugSeverity::Cosmetic);
        $this->bug('A critical thing', BugSeverity::Critical);
        $client->loginUser($this->curator());

        $text = $client->request('GET', '/moderate/bugs?sort=worst')->text();

        self::assertLessThan(
            mb_strpos($text, 'A cosmetic thing'),
            mb_strpos($text, 'A critical thing'),
            'critical must come first',
        );
    }

    public function testLowPriorityFirstIsTheOtherWayRound(): void
    {
        $client = $this->client();
        $this->bug('A cosmetic thing', BugSeverity::Cosmetic);
        $this->bug('A critical thing', BugSeverity::Critical);
        $client->loginUser($this->curator());

        $text = $client->request('GET', '/moderate/bugs?sort=smallest')->text();

        self::assertLessThan(
            mb_strpos($text, 'A critical thing'),
            mb_strpos($text, 'A cosmetic thing'),
            'cosmetic must come first',
        );
    }

    /**
     * Severity sorts on weight, not on the stored string.
     *
     * Alphabetically "cosmetic" precedes "critical", so a plain ORDER BY on the
     * column would put the least important bug at the top of "worst first".
     */
    public function testSeverityIsNotSortedAlphabetically(): void
    {
        $client = $this->client();
        $this->bug('Cosmetic one', BugSeverity::Cosmetic);
        $this->bug('Critical one', BugSeverity::Critical);
        $this->bug('Major one', BugSeverity::Major);
        $client->loginUser($this->curator());

        $text = $client->request('GET', '/moderate/bugs?sort=worst')->text();
        $order = [
            mb_strpos($text, 'Critical one'),
            mb_strpos($text, 'Major one'),
            mb_strpos($text, 'Cosmetic one'),
        ];

        self::assertSame($order, [...$order], 'sanity');
        self::assertLessThan($order[1], $order[0]);
        self::assertLessThan($order[2], $order[1]);
    }

    public function testOldestFirstReversesTheDefault(): void
    {
        $client = $this->client();
        $first = $this->bug('The older one');
        $this->bug('The newer one');
        $client->loginUser($this->curator());

        $newest = $client->request('GET', '/moderate/bugs')->text();
        self::assertLessThan(mb_strpos($newest, 'The older one'), mb_strpos($newest, 'The newer one'));

        $oldest = $client->request('GET', '/moderate/bugs?sort=oldest')->text();
        self::assertLessThan(mb_strpos($oldest, 'The newer one'), mb_strpos($oldest, 'The older one'));

        self::assertNotNull($first->getId());
    }

    // -- how a reporter hears back -----------------------------------------

    /**
     * A signed-in reporter hears back THROUGH their account.
     *
     * The outcome lands in `/messages` and the mail that follows is the
     * notification of it. Two reasons that is the right shape: a form post
     * leaves nothing in a sent folder, so their own messages are the only
     * record they have; and the address is the account's, not one typed into a
     * report, so a report cannot make our server mail somebody else.
     */
    public function testASignedInReporterGetsTheOutcomeInTheirMessages(): void
    {
        $client = $this->client();
        $rider = (new User())->setEmail('rider-reporter@cyclingcommons.org');
        $rider->setPassword('x');
        $rider->setDisplayName('Rider Reporter');
        $this->em()->persist($rider);
        $this->em()->flush();

        $report = $this->bug();
        $report->setUserId($rider->getId());
        $this->em()->flush();

        $client->loginUser($this->curator());
        $this->decide($client, $report, ['internal_note' => self::SECRET]);

        $this->em()->clear();
        $messages = $this->em()->getRepository(UserMessage::class)->findBy(['userId' => $rider->getId()]);

        self::assertCount(1, $messages);
        self::assertSame(UserMessageKind::BugOutcome, $messages[0]->getKind());
        self::assertSame('bug', $messages[0]->getChannel());
        self::assertSame($report->getId(), $messages[0]->getRefId());
        self::assertStringContainsString('Fixed. Thank you', (string) $messages[0]->getBodyText());
        // The curator-only note must not travel with it.
        self::assertStringNotContainsString('Dave', (string) $messages[0]->getBodyText());
    }

    /** No account, no message: the address they typed is the only way to reach them. */
    public function testAnAnonymousReporterGetsAPlainMailAndNoMessage(): void
    {
        $client = $this->client();
        $report = $this->bug(email: 'stranger@cyclingcommons.org');
        $client->loginUser($this->curator());

        $this->decide($client, $report);

        self::assertEmailCount(1, null, 'the outcome mail');
        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(UserMessage::class)->findBy(['channel' => 'bug']));
    }

    /** Changing the order must not silently drop the search somebody typed. */
    public function testTheOrderFormCarriesTheSearch(): void
    {
        $client = $this->client();
        $this->bug('Grey tiles');
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs?q=grey');
        $hidden = $page->filter('form.bugsort input[name="q"]');

        self::assertCount(1, $hidden);
        self::assertSame('grey', $hidden->attr('value'));
    }
}
