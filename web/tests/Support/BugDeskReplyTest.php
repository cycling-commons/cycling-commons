<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Support\BugStatus;
use App\Support\Entity\BugReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * What the bug desk asks for before a status mails the reporter
 * (docs/specs/contact-and-support.md §9, "The reply to the reporter").
 *
 * Fixed and Not changing this send the reply box to the reporter, once. The
 * browser refuses to submit those two with the box empty, the server refuses
 * it again, and a refused save comes back at the box with everything the
 * curator typed still in the form. Fixed also offers a default reply, in the
 * language the mail goes out in.
 */
final class BugDeskReplyTest extends WebTestCase
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

    private function curator(): User
    {
        $user = (new User())->setEmail('reply-desk@cyclingcommons.org');
        $user->setPassword('x');
        $user->setDisplayName('Reply Desk Curator');
        $user->setRoles(['ROLE_CURATOR']);
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function bug(?BugStatus $status = null, ?string $locale = null): BugReport
    {
        $report = new BugReport('The map will not load', 'It stayed grey.');
        $report->setReporterEmail('reporter@cyclingcommons.org');
        if (null !== $status) {
            $report->setStatus($status);
        }
        $report->setLocale($locale);
        $this->em()->persist($report);
        $this->em()->flush();

        return $report;
    }

    /**
     * @param array<string, string> $fields
     */
    private function decide(KernelBrowser $client, BugReport $report, array $fields): Crawler
    {
        $page = $client->request('GET', '/moderate/bugs/'.$report->getId());
        self::assertResponseIsSuccessful();

        return $client->request('POST', '/moderate/bugs/'.$report->getId().'/decide', $fields + [
            '_token' => (string) $page->filter('form.decide input[name="_token"]')->attr('value'),
            'severity' => $report->getSeverity()->value,
            'area' => $report->getArea()->value,
        ]);
    }

    private function fresh(BugReport $report): BugReport
    {
        $this->em()->clear();
        $fresh = $this->em()->find(BugReport::class, $report->getId());
        self::assertInstanceOf(BugReport::class, $fresh);

        return $fresh;
    }

    public function testTheReplyIsRequiredOnlyWhileTheStatusSendsIt(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $sending = $client->request('GET', '/moderate/bugs/'.$this->bug(BugStatus::Resolved)->getId());
        self::assertNotNull($sending->filter('#d-note')->attr('required'), 'Fixed sends the reply, so the browser asks for it');
        self::assertNotSame('', (string) $sending->filter('#d-note')->attr('data-missing'), 'the browser message is the desk\'s own sentence');

        $quiet = $client->request('GET', '/moderate/bugs/'.$this->bug()->getId());
        self::assertNull($quiet->filter('#d-note')->attr('required'), 'New sends nothing, so nothing is required');
        self::assertSame('resolved declined', $quiet->filter('#d-reply')->attr('data-when'), 'the script knows which statuses send it');
    }

    public function testARefusedSaveComesBackAtTheReplyWithWhatWasTyped(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());
        $report = $this->bug();

        $page = $this->decide($client, $report, [
            'status' => 'resolved',
            'outcome_note' => '   ',
            'internal_note' => 'Same cause as the tile outage.',
            'public_title' => 'Grey map on first load',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(BugStatus::New, $this->fresh($report)->getStatus(), 'the status must not have moved');

        $note = $page->filter('#d-note');
        self::assertSame('true', $note->attr('aria-invalid'));
        self::assertNotNull($note->attr('autofocus'), 'the page opens on the field that needs writing');
        self::assertSame('d-note-error', $note->attr('aria-describedby'));
        self::assertStringContainsString('Write what to tell the reporter first', $page->filter('#d-reply #d-note-error')->text(), 'the reason sits next to the field');
        self::assertCount(0, $page->filter('.cc-notice')->reduce(
            static fn (Crawler $n): bool => str_contains($n->text(), 'Write what to tell the reporter first'),
        ), 'and not only in a banner at the top');

        self::assertSame('resolved', $page->filter('#d-status option[selected]')->attr('value'), 'the status tried stays chosen, so the box stays open');
        self::assertSame('Same cause as the tile outage.', $page->filter('#d-internal')->text());
        self::assertSame('Grey map on first load', $page->filter('#d-ptitle')->attr('value'));
    }

    public function testNotChangingThisNeedsTheReplyToo(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());
        $report = $this->bug();

        $this->decide($client, $report, ['status' => 'declined', 'outcome_note' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(BugStatus::New, $this->fresh($report)->getStatus());
    }

    public function testTheDefaultReplyIsInTheReportersLanguage(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $fr = $client->request('GET', '/moderate/bugs/'.$this->bug(null, 'fr')->getId());
        self::assertStringStartsWith('Merci', (string) $fr->filter('#d-default')->attr('data-text'), 'the mail goes out in French, so the default does too');
        self::assertSame('resolved', $fr->filter('.d-default')->attr('data-when'), 'offered for Fixed only');

        $none = $client->request('GET', '/moderate/bugs/'.$this->bug()->getId());
        self::assertSame(
            'Thank you for reporting this. It is fixed now and will be live with the next update.',
            $none->filter('#d-default')->attr('data-text'),
            'no recorded language means the site default, as for the mail',
        );
    }

    /** Without scripting the box cannot fill the field, so the server does. */
    public function testTickingTheDefaultWithAnEmptyFieldSendsTheDefault(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());
        $report = $this->bug(null, 'nl');

        $this->decide($client, $report, ['status' => 'resolved', 'outcome_note' => '', 'outcome_default' => '1']);

        self::assertResponseRedirects('/moderate/bugs/'.$report->getId());
        $fresh = $this->fresh($report);
        self::assertSame(BugStatus::Resolved, $fresh->getStatus());
        self::assertStringStartsWith('Bedankt', (string) $fresh->getOutcomeNote());
        self::assertNotNull($fresh->getNotifiedAt(), 'and the reporter is told');
    }

    public function testTheDefaultNeverStandsInForADecline(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());
        $report = $this->bug();

        $this->decide($client, $report, ['status' => 'declined', 'outcome_note' => '', 'outcome_default' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(BugStatus::New, $this->fresh($report)->getStatus(), 'a decline always carries a reason somebody wrote');
    }

    public function testThePublishCheckboxCarriesNoWarningNote(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs/'.$this->bug()->getId());

        self::assertCount(0, $page->filter('form.decide .warn'));
        self::assertStringNotContainsString('which may name somebody', (string) $client->getResponse()->getContent());
    }
}
