<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Account;

use App\Account\DataExportService;
use App\Account\DormancySweep;
use App\Entity\AdminActionLog;
use App\Entity\User;
use App\Moderation\StatementGround;
use App\Service\GuardrailViolationException;
use App\Service\UserAdminService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * An administrator suspends an account for a number of days, or removes it,
 * and tells its holder why first (DSA Article 17(1)(c)). A suspended account
 * cannot sign in until the suspension ends, which it does by itself.
 *
 * @see docs/specs/account-and-auth.md §6.8
 */
final class AccountSuspensionTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private const string PASSWORD = 'correct horse battery';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testSuspendingStoresTheDecisionAuditsItAndEmailsTheReasons(): void
    {
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');
        $rider->recordDormancyNotice('m12', new \DateTimeImmutable('-1 day'));
        $this->em->flush();
        $before = new \DateTimeImmutable();

        $this->admin()->suspend($rider, $admin, 7, StatementGround::Abuse, 'Three threats to other riders in messages.');

        self::assertTrue($rider->isSuspendedAt(new \DateTimeImmutable()));
        self::assertFalse($rider->isSuspendedAt(new \DateTimeImmutable('+8 days')), 'it ends by itself');
        $until = $rider->getSuspendedUntil();
        self::assertNotNull($until);
        self::assertGreaterThanOrEqual($before->modify('+7 days')->getTimestamp(), $until->getTimestamp());
        self::assertSame(StatementGround::Abuse, $rider->getSuspensionGround());
        self::assertSame('Three threats to other riders in messages.', $rider->getSuspensionFacts());
        self::assertSame($admin->getId(), $rider->getSuspendedBy());
        self::assertNotNull($rider->getSuspendedAt());
        self::assertSame([], array_filter($rider->dormancyNoticesSent()), 'the dormancy clock starts again');

        $log = $this->em->getRepository(AdminActionLog::class)->findOneBy(['action' => UserAdminService::SUSPEND]);
        self::assertInstanceOf(AdminActionLog::class, $log);
        self::assertStringNotContainsString('threats', (string) $log->getNote(), 'the trail keeps the ground, not the facts');

        $mail = $this->onlyMail();
        self::assertSame($rider->getEmail(), $mail->getTo()[0]->getAddress());
        self::assertNotEmpty($mail->getReplyTo());
        $html = (string) $mail->getHtmlBody();
        foreach (['Your account is suspended', 'Three threats to other riders in messages.', 'An administrator made this decision.', 'reply to this email', 'Abuse'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
    }

    public function testASuspensionNeedsDaysAGroundAndTheFacts(): void
    {
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');

        foreach ([
            [0, StatementGround::Abuse, 'Facts.', 'account_suspension.admin.error_days'],
            [UserAdminService::SUSPENSION_MAX_DAYS + 1, StatementGround::Abuse, 'Facts.', 'account_suspension.admin.error_days'],
            [3, StatementGround::NotAccepted, 'Facts.', 'account_suspension.admin.error_ground'],
            [3, StatementGround::Abuse, '  ', 'account_suspension.admin.error_facts'],
        ] as [$days, $ground, $facts, $error]) {
            try {
                $this->admin()->suspend($rider, $admin, $days, $ground, $facts);
                self::fail('suspended with '.$error);
            } catch (\InvalidArgumentException $e) {
                self::assertSame($error, $e->getMessage());
            }
        }
        self::assertNull($rider->getSuspendedUntil());
        self::assertCount(0, self::getMailerMessages());

        $this->expectException(GuardrailViolationException::class);
        $this->admin()->suspend($admin, $admin, 3, StatementGround::Abuse, 'Myself.');
    }

    public function testASuspendedAccountCannotSignInAndSeesWhenItEnds(): void
    {
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');
        $this->admin()->suspend($rider, $admin, 3, StatementGround::Spam, 'Forty adverts in one hour.');
        $until = $rider->getSuspendedUntil();
        self::assertNotNull($until);

        $crawler = $this->signIn($rider);

        self::assertStringNotContainsString('/logout', (string) $this->client->getResponse()->getContent());
        $alert = $crawler->filter('.alert-error')->text();
        self::assertStringContainsString('suspended until', $alert);
        self::assertStringContainsString($until->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('H:i'), $alert);
        $this->em->clear();
        self::assertSame(0, $this->em->find(User::class, $rider->getId())?->getFailedLoginAttempts(), 'the right password is not a failed guess');
    }

    public function testAnEndedSuspensionSignsInAsBefore(): void
    {
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');
        $this->admin()->suspend($rider, $admin, 3, StatementGround::Spam, 'Adverts.');
        $this->em->getConnection()->executeStatement("UPDATE users SET suspended_until = NOW() - INTERVAL '1 minute' WHERE id = ?", [$rider->getId()]);
        $this->em->clear();

        $this->signIn($rider);

        self::assertResponseRedirects();
    }

    public function testASignedInRiderIsSignedOutOnTheirNextRequest(): void
    {
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');
        $this->client->loginUser($rider);
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $this->admin()->suspend($rider, $admin, 3, StatementGround::Abuse, 'Threats.');
        $this->client->request('GET', '/account');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * The remember-me cookie signs nobody in while the suspension runs: the
     * session is gone and the cookie alone comes back.
     */
    public function testTheRememberMeCookieDoesNotSignASuspendedAccountIn(): void
    {
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');
        $cookieName = (string) static::getContainer()->getParameter('cc.remember_me_cookie');

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form(['_username' => $rider->getEmail(), '_password' => self::PASSWORD]);
        $form['_remember_me']->tick();
        $this->client->submit($form);
        self::assertResponseRedirects();
        self::assertNotNull($this->client->getCookieJar()->get($cookieName), 'the remember-me cookie was set');

        // The cookie alone signs in, before the suspension.
        $this->client->getCookieJar()->expire('MOCKSESSID');
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $rider = $em->find(User::class, $rider->getId());
        $admin = $em->find(User::class, $admin->getId());
        self::assertNotNull($rider);
        self::assertNotNull($admin);
        $this->admin()->suspend($rider, $admin, 3, StatementGround::Abuse, 'Threats.');
        $this->client->getCookieJar()->expire('MOCKSESSID');
        $this->client->request('GET', '/account');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->request('GET', '/account');
        self::assertResponseRedirects(null, null, 'still not signed in on the next request');
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testLiftingASuspensionEndsItNow(): void
    {
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');
        $this->admin()->suspend($rider, $admin, 30, StatementGround::Abuse, 'Threats.');

        $this->admin()->liftSuspension($rider, $admin);

        self::assertFalse($rider->isSuspendedAt(new \DateTimeImmutable()));
        self::assertNotNull($this->em->getRepository(AdminActionLog::class)->findOneBy(['action' => UserAdminService::LIFT_SUSPENSION]));
    }

    public function testRemovingAnAccountForABreachEmailsTheReasonsThenDeletesIt(): void
    {
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');
        $id = $rider->getId();
        $address = $rider->getEmail();

        $this->admin()->removeAccountForBreach($rider, $admin, StatementGround::Misuse, 'Scraped the whole map with a script, twice after a warning.');

        $this->em->clear();
        self::assertNull($this->em->find(User::class, $id));
        $mail = $this->onlyMail();
        self::assertSame($address, $mail->getTo()[0]->getAddress());
        $html = (string) $mail->getHtmlBody();
        foreach (['We removed your account', 'Scraped the whole map with a script', 'An administrator made this decision.'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        $log = $this->em->getRepository(AdminActionLog::class)->findOneBy(['action' => UserAdminService::REMOVE_FOR_BREACH]);
        self::assertInstanceOf(AdminActionLog::class, $log);
        self::assertStringNotContainsString($address, (string) $log->getNote());
    }

    public function testARemovalForABreachNeedsAGroundAndTheFacts(): void
    {
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');

        try {
            $this->admin()->removeAccountForBreach($rider, $admin, StatementGround::Abuse, '');
            self::fail('removed without facts');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('account_suspension.admin.error_facts', $e->getMessage());
        }
        self::assertNotNull($this->em->find(User::class, $rider->getId()));
        self::assertCount(0, self::getMailerMessages());

        $this->expectException(GuardrailViolationException::class);
        $this->admin()->removeAccountForBreach($admin, $admin, StatementGround::Abuse, 'Myself.');
    }

    public function testTheExportCarriesTheSuspension(): void
    {
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');
        $this->admin()->suspend($rider, $admin, 3, StatementGround::Abuse, 'Threats.');

        $path = static::getContainer()->get(DataExportService::class)->export($rider);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $account = json_decode((string) $zip->getFromName('account.json'), true);
        $zip->close();
        @unlink($path);

        self::assertIsArray($account);
        self::assertSame('abuse', $account['suspension_ground']);
        self::assertSame('Threats.', $account['suspension_facts']);
        self::assertNotNull($account['suspended_until']);
        self::assertNotNull($account['suspended_at']);
        self::assertArrayNotHasKey('suspended_by', $account, 'who decided is the administrator\'s, not the rider\'s');
    }

    public function testASuspendedAccountIsNotWarnedAndItsIdleTimeCountsFromTheEnd(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T12:00:00+00:00');
        $rider = $this->user('dormant');
        $rider->recordLogin($now->modify('-12 months'));
        $this->em->flush();
        $this->em->getConnection()->executeStatement(
            "UPDATE users SET suspended_until = :until, suspended_at = :at, suspension_ground = 'abuse', suspension_facts = 'x' WHERE id = :id",
            ['until' => $now->modify('+2 days')->format('Y-m-d H:i:s'), 'at' => $now->modify('-5 days')->format('Y-m-d H:i:s'), 'id' => $rider->getId()],
        );
        $this->em->clear();

        $suspended = static::getContainer()->get(DormancySweep::class)->run($now);
        $after = static::getContainer()->get(DormancySweep::class)->run($now->modify('+3 months'));

        self::assertSame(0, $suspended['notified']['m12'], 'not while it cannot sign in');
        self::assertSame(0, $after['notified']['m12'], 'and the clock counts from the end of the suspension');
    }

    private function signIn(User $user): \Symfony\Component\DomCrawler\Crawler
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => $user->getEmail(),
            '_password' => self::PASSWORD,
        ]));
        if ($this->client->getResponse()->isRedirect('/login') || $this->client->getResponse()->isRedirect('http://localhost/login')) {
            return $this->client->followRedirect();
        }

        return $this->client->getCrawler();
    }

    private function onlyMail(): Email
    {
        $mails = self::getMailerMessages();
        self::assertCount(1, $mails);
        self::assertInstanceOf(Email::class, $mails[0]);

        return $mails[0];
    }

    private function admin(): UserAdminService
    {
        return static::getContainer()->get(UserAdminService::class);
    }

    /** @param list<string> $roles */
    private function user(string $kind, array $roles = []): User
    {
        $u = (new User())->setEmail($kind.'-'.uniqid('', true).'@suspension.test')->setDisplayName(ucfirst($kind))->setRoles($roles);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, self::PASSWORD));
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }
}
