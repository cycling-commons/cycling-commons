<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Controller\Admin\DashboardController;
use App\Controller\Admin\UserCrudController;
use App\Entity\AdminActionLog;
use App\Entity\User;
use App\Moderation\Entity\UnsentStatement;
use App\Moderation\StatementDecision;
use App\Moderation\StatementGround;
use App\Moderation\StatementOfReasonsMailer;
use App\Moderation\UnsentStatements;
use App\Service\UserAdminService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An account decision whose email did not go out is not lost: the whole
 * statement waits under Unsent statements, the administrator is told at
 * once, and can send it again from there (DSA Article 17(1)(c)).
 *
 * @see docs/specs/account-and-auth.md §6.8
 */
final class UnsentStatementTest extends WebTestCase
{
    use MailerAssertionsTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var MailerInterface&object{fail: bool} */
    private MailerInterface $transport;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->switchableStatementMailer();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testARemovalWhoseEmailFailsKeepsTheWholeStatement(): void
    {
        $this->mailFails();
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');
        $rider->setLocale('nl');
        $this->em->flush();
        $id = (int) $rider->getId();
        $address = $rider->getEmail();

        $sent = $this->admin()->removeAccountForBreach($rider, $admin, StatementGround::Misuse, 'Scraped the map with a script.');

        self::assertFalse($sent, 'the caller is told the mail did not go');
        $this->em->clear();
        self::assertNull($this->em->find(User::class, $id), 'the removal stands');
        $row = $this->onlyUnsent();
        self::assertSame($address, $row->getAddress());
        self::assertSame('nl', $row->getLocale());
        self::assertNull($row->getUserId(), 'the account is gone');
        $statement = $row->statement();
        self::assertNotNull($statement);
        self::assertSame(StatementDecision::AccountRemoved, $statement->decision);
        self::assertSame(StatementGround::Misuse, $statement->ground);
        self::assertSame('Scraped the map with a script.', $statement->facts);
        self::assertSame('ACCOUNT-'.$id, $statement->reference);
    }

    public function testASuspensionWhoseEmailFailsIsKeptAndADeliveredOneIsNot(): void
    {
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $delivered = $this->user('rider');
        self::assertTrue($this->admin()->suspend($delivered, $admin, 3, StatementGround::Abuse, 'Threats.'));
        self::assertSame([], $this->em->getRepository(UnsentStatement::class)->findAll());
    }

    public function testAFailedSuspensionEmailIsKeptForItsAccount(): void
    {
        $this->mailFails();
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');

        self::assertFalse($this->admin()->suspend($rider, $admin, 3, StatementGround::Abuse, 'Threats.'));

        $row = $this->onlyUnsent();
        self::assertSame($rider->getId(), $row->getUserId());
        self::assertSame(StatementDecision::AccountSuspended, $row->statement()?->decision);
        self::assertNotNull($row->statement()->until);
    }

    public function testTheAdministratorIsWarnedAndCanSendItAgain(): void
    {
        $this->mailFails();
        $admin = $this->user('admin', ['ROLE_ADMIN'], twoFa: true);
        $rider = $this->user('rider');
        $address = $rider->getEmail();
        $this->client->loginUser($admin);

        $page = $this->client->request('GET', $this->actionUrl(UserAdminService::REMOVE_FOR_BREACH, (int) $rider->getId()));
        $this->client->request('POST', $this->actionUrl(UserAdminService::REMOVE_FOR_BREACH, (int) $rider->getId()), [
            'token' => (string) $page->filter('form input[name="token"]')->attr('value'),
            'ground' => 'misuse',
            'facts' => 'Scraped the map with a script.',
        ]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('[class*="warning"]', 'did not go out');
        self::assertSelectorTextNotContains('body', 'The reasons went to its address');

        $list = $this->client->request('GET', '/admin/unsent-statements');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main, body', $address);
        self::assertSelectorTextContains('main, body', 'Scraped the map with a script.');

        $this->mailWorks();
        $this->client->submit($list->filter('form.unsent-resend')->form());
        self::assertResponseRedirects('/admin/unsent-statements');
        $mail = self::getMailerMessages();
        self::assertCount(1, $mail);
        self::assertInstanceOf(Email::class, $mail[0]);
        self::assertSame($address, $mail[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('Scraped the map with a script.', (string) $mail[0]->getHtmlBody());
        $this->client->followRedirect();
        self::assertSelectorTextContains('[class*="success"]', 'sent');
        self::assertSame([], $this->em->getRepository(UnsentStatement::class)->findAll(), 'a delivered statement leaves the list');
        self::assertNotNull($this->em->getRepository(AdminActionLog::class)->findOneBy(['action' => UnsentStatements::ACTION_RESENT]));
    }

    public function testAResendThatFailsAgainStaysAndADiscardIsAudited(): void
    {
        $this->mailFails();
        $admin = $this->user('admin', ['ROLE_ADMIN'], twoFa: true);
        $rider = $this->user('rider');
        $this->admin()->suspend($rider, $admin, 3, StatementGround::Abuse, 'Threats.');
        $row = $this->onlyUnsent();

        self::assertFalse(static::getContainer()->get(UnsentStatements::class)->resend((int) $row->getId(), $admin));
        $this->em->clear();
        $row = $this->onlyUnsent();
        self::assertSame(2, $row->getAttempts());

        static::getContainer()->get(UnsentStatements::class)->discard((int) $row->getId(), $this->em->find(User::class, $admin->getId()) ?? $admin);
        self::assertSame([], $this->em->getRepository(UnsentStatement::class)->findAll());
        $log = $this->em->getRepository(AdminActionLog::class)->findOneBy(['action' => UnsentStatements::ACTION_DISCARDED]);
        self::assertInstanceOf(AdminActionLog::class, $log);
        self::assertStringNotContainsString($rider->getEmail(), (string) $log->getNote(), 'the trail keeps the reference, not the address');
    }

    public function testDeletingTheSuspendedAccountTakesItsUnsentStatementAlong(): void
    {
        $this->mailFails();
        $admin = $this->user('admin', ['ROLE_ADMIN']);
        $rider = $this->user('rider');
        $this->admin()->suspend($rider, $admin, 3, StatementGround::Abuse, 'Threats.');
        self::assertCount(1, $this->em->getRepository(UnsentStatement::class)->findAll());

        $this->admin()->removeAccount($rider, $admin);

        $this->em->clear();
        self::assertSame([], $this->em->getRepository(UnsentStatement::class)->findAll());
    }

    private function onlyUnsent(): UnsentStatement
    {
        $rows = $this->em->getRepository(UnsentStatement::class)->findAll();
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /** Every statement email from here on fails at the transport. */
    private function mailFails(): void
    {
        $this->transport->fail = true;
    }

    private function mailWorks(): void
    {
        $this->transport->fail = false;
    }

    /**
     * The statement mailer, on a transport a test can break. Set before
     * anything holds the service: the client does not reboot.
     */
    private function switchableStatementMailer(): void
    {
        $c = static::getContainer();
        $this->transport = new class($c->get(MailerInterface::class)) implements MailerInterface {
            public bool $fail = false;

            public function __construct(private readonly MailerInterface $inner)
            {
            }

            #[\Override]
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($this->fail) {
                    throw new TransportException('Connection refused.');
                }
                $this->inner->send($message, $envelope);
            }
        };
        $c->set(StatementOfReasonsMailer::class, new StatementOfReasonsMailer(
            $this->transport,
            $c->get(TranslatorInterface::class),
            new NullLogger(),
            'https://commons.test',
            (string) $c->getParameter('kernel.default_locale'),
            (string) $c->getParameter('cc.support.from_email'),
            (string) $c->getParameter('cc.support.public_email'),
        ));
    }

    private function actionUrl(string $action, int $entityId): string
    {
        return static::getContainer()->get(AdminUrlGenerator::class)->setDashboard(DashboardController::class)
            ->setController(UserCrudController::class)->setAction($action)->setEntityId($entityId)->generateUrl();
    }

    private function admin(): UserAdminService
    {
        return static::getContainer()->get(UserAdminService::class);
    }

    /** @param list<string> $roles */
    private function user(string $kind, array $roles = [], bool $twoFa = false): User
    {
        $u = (new User())->setEmail($kind.'-'.uniqid('', true).'@unsent.test')->setDisplayName(ucfirst($kind))->setRoles($roles);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        if ($twoFa) {
            $u->setTotpSecret('JBSWY3DPEHPK3PXP');
            $u->setTwoFaEnabled(true);
        }
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }
}
