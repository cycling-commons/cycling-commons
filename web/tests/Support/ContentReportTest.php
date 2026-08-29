<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Security\FormGuard;
use App\Support\Entity\ContentReport;
use App\Support\ReportGround;
use App\Support\ReportStatus;
use App\Support\ReportTarget;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Report this", from anybody, about anything but a photo
 * (docs/specs/content-reports.md).
 *
 * The properties this file exists to hold down, in the order they matter:
 *
 * * **No account is needed** (DSA Article 16(1)). A person who is not a member
 *   of this site is exactly the person the Article is written for.
 * * **The form is not an existence oracle.** A GET renders the same page for an
 *   id that exists and one that does not, and the rate limit fires before the
 *   lookup, so a 429 cannot be used as one either.
 * * **The reporter is answered whatever the outcome** (Article 16(5)), and
 *   **only an upheld report tells the author** (Article 17(1)), because only an
 *   upheld report restricted anybody.
 * * **The statement of reasons goes exactly once.** A curator who edits their
 *   decision must not send a second one.
 * * **No raw IP is stored.** The column holds a keyed hash, which is what makes
 *   the rate limit possible without keeping the address.
 */
final class ContentReportTest extends WebTestCase
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

    /** @return list<ContentReport> */
    private function reports(): array
    {
        $this->em()->clear();

        /** @var list<ContentReport> $rows */
        $rows = $this->em()->getRepository(ContentReport::class)->findBy([], ['createdAt' => 'ASC']);

        return $rows;
    }

    /**
     * File one report, the way a browser does.
     *
     * The stamp is minted for a moment far enough in the past to clear
     * {@see FormGuard::MIN_SECONDS}, because a test that really waited four
     * seconds per report would add a minute to the suite for nothing.
     */
    private function file(
        KernelBrowser $client,
        string $type = 'route',
        string $id = '1',
        string $ground = 'untrue',
        ?string $contact = 'reporter@cyclingcommons.org',
    ): void {
        $page = $client->request('GET', '/report/'.$type.'/'.$id);
        self::assertResponseIsSuccessful();

        $guard = static::getContainer()->get(FormGuard::class);
        $token = (string) $page->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/report/'.$type.'/'.$id, [
            '_token' => $token,
            FormGuard::STAMP => $guard->stamp(new \DateTimeImmutable('-30 seconds')),
            'ground' => $ground,
            'reason' => 'The gate at the top has been locked since spring and the way through is fenced.',
            'contact' => $contact ?? '',
        ]);
    }

    private function curator(): User
    {
        $user = (new User())->setEmail('report-desk@cyclingcommons.org');
        $user->setPassword('x');
        $user->setDisplayName('Report Desk Curator');
        $user->setRoles(['ROLE_CURATOR']);
        // Elevated roles must be TOTP-enrolled or TwoFactorSetupEnforcer sends
        // every curator page to /2fa/setup (docs/specs/account-and-auth.md).
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function deskToken(KernelBrowser $client, ContentReport $report): string
    {
        $page = $client->request('GET', '/moderate/reports/'.$report->getId());
        self::assertResponseIsSuccessful();

        return (string) $page->filter('form.decide input[name="_token"]')->attr('value');
    }

    // -- filing ----------------------------------------------------------

    public function testAnyoneCanReportWithoutAnAccount(): void
    {
        $client = $this->client();
        $this->file($client);

        self::assertResponseIsSuccessful();

        $rows = $this->reports();
        self::assertCount(1, $rows);
        self::assertSame(ReportTarget::Route, $rows[0]->getTargetType());
        self::assertSame('1', $rows[0]->getTargetId());
        self::assertSame(ReportGround::Untrue, $rows[0]->getGround());
        self::assertSame(ReportStatus::Open, $rows[0]->getStatus());
    }

    /** A report we cannot answer is still a report, and is still kept. */
    public function testAReportWithNoAddressIsAccepted(): void
    {
        $client = $this->client();
        $this->file($client, contact: null);

        self::assertResponseIsSuccessful();
        self::assertNull($this->reports()[0]->getReporterContact());
    }

    public function testTheReporterIpIsHashedAndNotStored(): void
    {
        $client = $this->client();
        $this->file($client);

        $key = $this->reports()[0]->getReporterKey();
        self::assertSame(64, \strlen($key), 'a sha256 hex digest');
        self::assertStringNotContainsString('127.0.0.1', $key);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $key);
    }

    /**
     * The form must not answer "does this exist?".
     *
     * Both ids render the same page, and both accept a report. The one that
     * points at nothing is closed as moot later, by a person.
     */
    public function testTheFormIsNotAnExistenceOracle(): void
    {
        $client = $this->client();

        $client->request('GET', '/report/route/1');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/report/route/999999');
        self::assertResponseIsSuccessful();
    }

    public function testAnUnknownKindIsNotFound(): void
    {
        $client = $this->client();
        $client->request('GET', '/report/nonsense/1');

        self::assertResponseStatusCodeSame(404);
    }

    public function testARiderReportNeedsAUuidAndNotANumber(): void
    {
        $client = $this->client();
        $client->request('GET', '/report/rider/12');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnEmptyReasonIsRefused(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/report/route/1');
        $guard = static::getContainer()->get(FormGuard::class);

        $client->request('POST', '/report/route/1', [
            '_token' => (string) $page->filter('input[name="_token"]')->attr('value'),
            FormGuard::STAMP => $guard->stamp(new \DateTimeImmutable('-30 seconds')),
            'ground' => 'untrue',
            'reason' => '   ',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->reports());
    }

    public function testAReportFiledInstantlyIsRefused(): void
    {
        $client = $this->client();
        $page = $client->request('GET', '/report/route/1');

        $client->request('POST', '/report/route/1', [
            '_token' => (string) $page->filter('input[name="_token"]')->attr('value'),
            FormGuard::STAMP => (string) $page->filter('input[name="'.FormGuard::STAMP.'"]')->attr('value'),
            'ground' => 'untrue',
            'reason' => 'Filled in by something that does not read.',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->reports());
    }

    // -- the desk --------------------------------------------------------

    public function testTheDeskIsForCuratorsOnly(): void
    {
        $client = $this->client();
        $client->request('GET', '/moderate/reports');

        self::assertResponseStatusCodeSame(302);
    }

    public function testACuratorSeesTheOpenReport(): void
    {
        $client = $this->client();
        $this->file($client);
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/reports');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('locked since spring', $page->text());
    }

    /**
     * The desk decides about the report, never about the content.
     *
     * `one-way-to-moderate`: a delete button here would be a second moderation
     * path with its own history and its own permissions.
     */
    public function testTheDeskHasNoWayToTouchTheContent(): void
    {
        $client = $this->client();
        $this->file($client);
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/reports/'.$this->reports()[0]->getId());
        self::assertResponseIsSuccessful();

        /** @var list<string> $names */
        $names = $page->filter('form.decide [name]')->extract(['name']);
        self::assertSame(['_token', 'status', 'note'], $names);
    }

    public function testADecisionNeedsANote(): void
    {
        $client = $this->client();
        $this->file($client);
        $client->loginUser($this->curator());

        $report = $this->reports()[0];
        $token = $this->deskToken($client, $report);

        $client->request('POST', '/moderate/reports/'.$report->getId().'/decide', [
            '_token' => $token,
            'status' => 'upheld',
            'note' => '  ',
        ]);

        self::assertSame(ReportStatus::Open, $this->reports()[0]->getStatus());
    }

    public function testACuratorCannotPutAReportBackToOpen(): void
    {
        $client = $this->client();
        $this->file($client);
        $client->loginUser($this->curator());

        $report = $this->reports()[0];
        $token = $this->deskToken($client, $report);

        $client->request('POST', '/moderate/reports/'.$report->getId().'/decide', [
            '_token' => $token,
            'status' => 'open',
            'note' => 'Never mind.',
        ]);

        self::assertNull($this->reports()[0]->getDecidedAt());
    }

    public function testTheReporterIsMailedWhateverTheOutcome(): void
    {
        $client = $this->client();
        $this->file($client);

        // Asserted here, not after the desk requests: the mailer collector is
        // per request, so a later GET clears what the POST before it sent.
        self::assertEmailCount(1, null, 'the Article 16(4) acknowledgement');

        $client->loginUser($this->curator());
        $report = $this->reports()[0];
        $token = $this->deskToken($client, $report);

        $client->request('POST', '/moderate/reports/'.$report->getId().'/decide', [
            '_token' => $token,
            'status' => 'rejected',
            'note' => 'The gate is a seasonal one and the way is public again in May.',
        ]);

        $decided = $this->reports()[0];
        self::assertSame(ReportStatus::Rejected, $decided->getStatus());
        self::assertNotNull($decided->getDecidedAt());
        self::assertEmailCount(1, null, 'the Article 16(5) outcome, whatever it was');
    }

    /** No address, no mail, and no error either. */
    public function testAnAnonymousReporterIsNotMailed(): void
    {
        $client = $this->client();
        $this->file($client, contact: null);
        self::assertEmailCount(0, null, 'nothing to acknowledge to');

        $client->loginUser($this->curator());
        $report = $this->reports()[0];
        $token = $this->deskToken($client, $report);

        $client->request('POST', '/moderate/reports/'.$report->getId().'/decide', [
            '_token' => $token,
            'status' => 'upheld',
            'note' => 'Checked on the ground, the gate is locked.',
        ]);

        self::assertSame(ReportStatus::Upheld, $this->reports()[0]->getStatus());
        self::assertEmailCount(0);
    }

    /** Article 17 is owed for a restriction, so a rejected report owes nothing. */
    public function testARejectedReportNeverSendsAStatementOfReasons(): void
    {
        $report = new ContentReport(
            \Symfony\Component\Uid\Uuid::v4(),
            ReportTarget::Route,
            '1',
            ReportGround::Untrue,
            'A reason.',
            str_repeat('a', 64),
            new \DateTimeImmutable(),
        );

        $report->decide(ReportStatus::Rejected, 'Nothing wrong.', 1, new \DateTimeImmutable());
        self::assertFalse($report->getStatus()->owesStatementOfReasons());

        $report->decide(ReportStatus::Upheld, 'On reflection, it is wrong.', 1, new \DateTimeImmutable());
        self::assertTrue($report->getStatus()->owesStatementOfReasons());
    }
}
