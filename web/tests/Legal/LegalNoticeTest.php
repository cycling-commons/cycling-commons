<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Legal;

use App\Entity\User;
use App\Legal\LegalNotice;
use App\Legal\LegalPageView;
use App\Legal\LegalVersions;
use App\Legal\PrivacyNoticeVersions;
use App\Legal\TermsVersions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Telling people when the privacy notice or the terms change (owner
 * 2026-10-09; DSA Art. 14(2); GDPR Arts. 12-13). Every version names its
 * effective date and whether it is significant. A significant version is
 * emailed to every account at least LegalVersions::NOTICE_DAYS days before it
 * applies, from version LegalVersions::NOTICE_RULE_FROM on; both pages show a
 * bar to a signed-in reader until they open the page.
 */
final class LegalNoticeTest extends WebTestCase
{
    /** @return iterable<string, array{class-string<LegalVersions>}> */
    public static function pages(): iterable
    {
        yield 'privacy' => [PrivacyNoticeVersions::class];
        yield 'terms' => [TermsVersions::class];
    }

    /** @param class-string<LegalVersions> $versions */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testEveryVersionNamesItsEffectiveDateAndKeepsTheNoticePeriod(string $versions): void
    {
        $previous = PHP_INT_MAX;
        foreach ($versions::all() as $v) {
            self::assertLessThan($previous, $v['number'], 'newest first');
            $previous = $v['number'];
            self::assertGreaterThanOrEqual($v['date'], $v['effective'], "v{$v['number']} applies on or after it is published");
            if ($v['significant'] && $v['number'] >= LegalVersions::NOTICE_RULE_FROM) {
                $earliest = (new \DateTimeImmutable($v['date']))->modify('+'.LegalVersions::NOTICE_DAYS.' days')->format('Y-m-d');
                self::assertGreaterThanOrEqual($earliest, $v['effective'], "v{$v['number']} is significant: at least ".LegalVersions::NOTICE_DAYS.' days of notice');
            }
        }
        self::assertSame($versions::latest()['number'], $versions::CURRENT);
    }

    public function testACurrentAndAnUpcomingVersionAreToldApartByTheEffectiveDate(): void
    {
        $versions = new class extends LegalVersions {
            public const string PAGE = 'terms';

            #[\Override]
            protected static function entries(): array
            {
                return [
                    ['number' => 4, 'date' => '2026-11-01', 'effective' => '2026-12-01', 'significant' => true, 'changes' => []],
                    ['number' => 3, 'date' => '2026-10-01', 'effective' => '2026-10-01', 'significant' => false, 'changes' => []],
                ];
            }
        };
        $before = new \DateTimeImmutable('2026-11-15');
        self::assertSame(3, $versions::current($before)['number']);
        self::assertSame(4, $versions::upcoming($before)['number'] ?? null);
        $after = new \DateTimeImmutable('2026-12-01');
        self::assertSame(4, $versions::current($after)['number']);
        self::assertNull($versions::upcoming($after));
    }

    public function testATermsBarShowsUntilTheTermsAreOpened(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('terms-bar@example.test')->setPassword('x');
        $user->setPrivacyVersionSeen(PrivacyNoticeVersions::CURRENT);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $client->request('GET', '/blog');
        self::assertSelectorExists('[data-terms-changed]');

        $client->request('GET', '/terms');
        $em->clear();
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);
        self::assertSame(TermsVersions::CURRENT, $reloaded->getTermsVersionSeen());

        $client->request('GET', '/blog');
        self::assertSelectorNotExists('[data-terms-changed]');
    }

    public function testASignificantVersionIsMailedOnceToEveryAccountInItsLanguage(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement("DELETE FROM users WHERE email LIKE 'legal-notice-%@example.test'");
        $nl = (new User())->setEmail('legal-notice-nl@example.test')->setPassword('x')->setLocale('nl')->setEmailVerified(true);
        $en = (new User())->setEmail('legal-notice-en@example.test')->setPassword('x')->setEmailVerified(true);
        $em->persist($nl);
        $em->persist($en);
        $em->flush();

        $notice = $this->notice();
        $today = new \DateTimeImmutable('2026-11-01');
        $version = self::version(99);

        $result = $notice->announce('terms', $version, $today);
        self::assertGreaterThanOrEqual(2, $result['sent']);
        self::assertSame(0, $result['failed']);
        self::assertEmailCount($result['sent']);
        $mails = self::getMailerMessages();
        $toNl = array_values(array_filter($mails, static fn ($m): bool => str_contains((string) $m->getHeaders()->get('To')?->getBodyAsString(), 'legal-notice-nl@')));
        self::assertCount(1, $toNl);
        self::assertStringContainsString('De gebruiksvoorwaarden veranderen op', (string) $toNl[0]->getHeaders()->get('Subject')?->getBodyAsString());

        self::assertSame(['sent' => 0, 'failed' => 0], $notice->announce('terms', $version, $today), 'a second run mails nobody twice');
    }

    /**
     * A deletion code that ran out an hour later leaves deletion_requested_at
     * behind; the account is still open and its holder still has to be told.
     */
    public function testAnAccountThatOnceStartedADeletionIsStillMailed(): void
    {
        $this->account('legal-notice-left@example.test', verified: true)->setDeletionRequestedAt(new \DateTimeImmutable('2026-10-01 12:00'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->notice()->announce('terms', self::version(97), new \DateTimeImmutable('2026-11-01'));

        self::assertCount(1, $this->mailsTo('legal-notice-left@'));
    }

    /** An address nobody confirmed may be a stranger's, typed by a bot: it is not mailed. */
    public function testAnUnconfirmedAddressIsNotMailed(): void
    {
        $this->account('legal-notice-unconfirmed@example.test', verified: false);

        $this->notice()->announce('terms', self::version(96), new \DateTimeImmutable('2026-11-01'));

        self::assertCount(0, $this->mailsTo('legal-notice-unconfirmed@'));
    }

    /**
     * An account that signed in before sign-in needed a confirmed address is
     * a person who uses the Commons (account-and-auth.md §6.7): it is told.
     */
    public function testAnUnconfirmedAccountThatHasSignedInIsMailed(): void
    {
        $this->account('legal-notice-early@example.test', verified: false)->recordLogin(new \DateTimeImmutable('2026-06-01 12:00'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->notice()->announce('terms', self::version(93), new \DateTimeImmutable('2026-11-01'));

        self::assertCount(1, $this->mailsTo('legal-notice-early@'));
    }

    /**
     * One address the mail server refuses does not stop the announcement: the
     * rest are mailed, the refusal is logged by account id without the
     * address, and a later run tries that account again.
     */
    public function testARefusedAddressIsSkippedLoggedByIdAndTriedAgainNextRun(): void
    {
        $refused = $this->account('legal-notice-refused@example.test', verified: true);
        $this->account('legal-notice-fine@example.test', verified: true);
        $realMailer = static::getContainer()->get(MailerInterface::class);
        $mailer = new class($realMailer) implements MailerInterface {
            public bool $refuse = true;

            public function __construct(private readonly MailerInterface $inner)
            {
            }

            #[\Override]
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($this->refuse && $message instanceof Email && str_starts_with($message->getTo()[0]->getAddress(), 'legal-notice-refused@')) {
                    throw new TransportException('550 mailbox unavailable');
                }
                $this->inner->send($message, $envelope);
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<mixed>}> */
            public array $records = [];

            #[\Override]
            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $notice = $this->notice($mailer, $logger);
        $version = self::version(95);
        $today = new \DateTimeImmutable('2026-11-01');

        $result = $notice->announce('terms', $version, $today);

        self::assertSame(1, $result['failed']);
        self::assertCount(1, $this->mailsTo('legal-notice-fine@'));
        self::assertCount(1, $logger->records);
        self::assertSame($refused->getId(), $logger->records[0]['context']['user_id'] ?? null);
        self::assertStringNotContainsString('legal-notice-refused', json_encode($logger->records, JSON_THROW_ON_ERROR), 'the log names the account, never the address');

        $mailer->refuse = false;
        self::assertSame(['sent' => 1, 'failed' => 0], $notice->announce('terms', $version, $today), 'the next run mails the refused account only');
    }

    /** The email links the new text (`?v=next`): without its files in every language there is nothing to link. */
    public function testAVersionWhoseNewTextIsMissingIsRefused(): void
    {
        $dir = $this->nextTexts(['en', 'fr', 'nl', 'de']);
        $notice = $this->notice(nextTextsDir: $dir);

        try {
            $notice->announce('terms', self::version(94), new \DateTimeImmutable('2026-11-01'));
            self::fail('announced without the new text in Spanish');
        } catch (\DomainException $e) {
            self::assertStringContainsString('terms_next.es.yaml', $e->getMessage());
        }
        self::assertEmailCount(0);
    }

    public function testANoticeShorterThanTheRuleIsRefused(): void
    {
        $notice = $this->notice();
        $version = ['number' => 98, 'date' => '2026-11-01', 'effective' => '2026-11-20', 'significant' => true, 'changes' => []];

        $this->expectException(\DomainException::class);
        $notice->announce('terms', $version, new \DateTimeImmutable('2026-11-01'));
    }

    public function testTheCommandRefusesAVersionThatIsNotSignificantOrAlreadyApplies(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:legal:announce'));

        self::assertSame(Command::FAILURE, $tester->execute(['page' => 'terms', 'version' => (string) TermsVersions::CURRENT]));
        self::assertStringContainsString('not significant', $tester->getDisplay());
    }

    public function testThePagesShowNoRawKeys(): void
    {
        $client = static::createClient();
        foreach (['/privacy', '/terms', '/nl/privacy'] as $url) {
            $client->request('GET', $url);
            $html = (string) $client->getResponse()->getContent();
            self::assertDoesNotMatchRegularExpression('/>\s*(privacy|terms)\.[a-z0-9_.]+\s*</', $html, $url);
        }
    }

    /** @var list<string> */
    private array $scratchDirs = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
        $this->scratchDirs = [];
        parent::tearDown();
    }

    /**
     * The notice service over a translations directory that holds the
     * announced text in every language, unless one is given.
     */
    private function notice(?MailerInterface $mailer = null, ?LoggerInterface $logger = null, ?string $nextTextsDir = null): LegalNotice
    {
        $c = static::getContainer();

        return new LegalNotice(
            $c->get(Connection::class),
            $mailer ?? $c->get(MailerInterface::class),
            $c->get('translator'),
            $c->get(UrlGeneratorInterface::class),
            new LegalPageView($nextTextsDir ?? $this->nextTexts(LegalPageView::LOCALES)),
            $logger ?? new NullLogger(),
        );
    }

    /** @param list<string> $locales */
    private function nextTexts(array $locales): string
    {
        $dir = sys_get_temp_dir().'/legal-next-'.bin2hex(random_bytes(4));
        mkdir($dir);
        foreach ($locales as $locale) {
            file_put_contents("{$dir}/terms_next.{$locale}.yaml", "terms: {}\n");
        }
        $this->scratchDirs[] = $dir;

        return $dir;
    }

    /** @return array{number: int, date: string, effective: string, significant: bool, changes: list<string>} */
    private static function version(int $number): array
    {
        return ['number' => $number, 'date' => '2026-11-01', 'effective' => '2026-12-10', 'significant' => true, 'changes' => ['terms.change.v1_first']];
    }

    private function account(string $email, bool $verified): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($email)->setPassword('x')->setEmailVerified($verified);
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** @return list<RawMessage> */
    private function mailsTo(string $prefix): array
    {
        return array_values(array_filter(
            self::getMailerMessages(),
            static fn ($m): bool => str_contains((string) $m->getHeaders()->get('To')?->getBodyAsString(), $prefix),
        ));
    }
}
