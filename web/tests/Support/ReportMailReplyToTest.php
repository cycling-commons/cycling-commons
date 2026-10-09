<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Media\MediaEscalationService;
use App\Media\MediaTakedownService;
use App\Messaging\MessageService;
use App\Moderation\DeskSeen;
use App\Moderation\StatementDecision;
use App\Moderation\StatementGround;
use App\Moderation\StatementOfReasons;
use App\Moderation\StatementOfReasonsMailer;
use App\Support\ContentReportService;
use App\Support\ReportGround;
use App\Support\ReportStatus;
use App\Support\ReportTarget;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The report mails tell people to reply, so a reply has to reach somebody.
 *
 * They are sent from the no-reply sender, so every one carries the address the
 * contact page publishes (`cc.support.public_email`) as its Reply-To. A
 * deployment with no such address gets mails that do not ask for a reply.
 *
 * @see docs/specs/content-reports.md §6, §7
 */
final class ReportMailReplyToTest extends WebTestCase
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

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName('Reply '.substr($email, 0, 8));
        $user->setPublicProfile(true);
        $user->setRoles($roles);
        if ([] !== $roles) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function publicEmail(): string
    {
        $value = static::getContainer()->getParameter('cc.support.public_email');
        self::assertIsString($value);
        self::assertNotSame('', $value);

        return $value;
    }

    /**
     * @param list<Address> $addresses
     *
     * @return list<string>
     */
    private static function addresses(array $addresses): array
    {
        return array_map(static fn (Address $a): string => $a->getAddress(), $addresses);
    }

    /** @return list<Email> what the mailer sent in the last request or service call */
    private static function sent(): array
    {
        $out = [];
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            $out[] = $message;
        }

        return $out;
    }

    public function testEveryReportMailCanBeRepliedTo(): void
    {
        $client = $this->client();
        $author = $this->user('reply-author@example.test');

        $report = static::getContainer()->get(ContentReportService::class)->file(
            ReportTarget::DisplayName,
            $author->getUuid()?->toRfc4122() ?? '',
            ReportGround::Abuse,
            'The display name is a slur.',
            'reply-reporter@example.test',
            null,
            '203.0.113.77',
        );

        // The Article 16(4) acknowledgement.
        $sent = self::sent();
        self::assertCount(1, $sent);
        self::assertSame([$this->publicEmail()], self::addresses($sent[0]->getReplyTo()));

        $client->loginUser($this->user('reply-curator@example.test', ['ROLE_CURATOR']));
        $page = $client->request('GET', '/moderate/reports/'.$report->getId());
        self::assertResponseIsSuccessful();
        $token = (string) $page->filter('form.decide input[name="_token"]')->attr('value');

        $client->request('POST', '/moderate/reports/'.$report->getId().'/decide', [
            '_token' => $token,
            'status' => 'upheld',
            'note' => 'The name has been reset.',
        ]);
        self::assertResponseRedirects();

        // The Article 16(5) outcome and the Article 17 statement of reasons.
        $sent = self::sent();
        self::assertCount(2, $sent);
        foreach ($sent as $email) {
            self::assertSame([$this->publicEmail()], self::addresses($email->getReplyTo()), (string) $email->getSubject());
            self::assertStringContainsString('reply to this email', strtolower((string) $email->getHtmlBody()));
        }
    }

    /**
     * No published address, no promise of a reply: the redress line points
     * to the contact page instead, and no Reply-To is set.
     */
    public function testWithNoPublishedAddressTheMailsDoNotAskForAReply(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $service = new ContentReportService(
            $container->get(EntityManagerInterface::class),
            $container->get(MailerInterface::class),
            $container->get(ClockInterface::class),
            $container->get(MediaTakedownService::class),
            $container->get(MediaEscalationService::class),
            $container->get(DeskSeen::class),
            $container->get(MessageService::class),
            'test-secret',
            'noreply@example.test',
            '',
        );
        $statements = new StatementOfReasonsMailer(
            $container->get(MailerInterface::class),
            $container->get(TranslatorInterface::class),
            $container->get(LoggerInterface::class),
            'https://cyclingcommons.example',
            'en',
            'noreply@example.test',
            '',
        );

        $author = $this->user('reply-none@example.test');
        $report = $service->file(
            ReportTarget::DisplayName,
            $author->getUuid()?->toRfc4122() ?? '',
            ReportGround::Abuse,
            'The display name is a slur.',
            'reply-none-reporter@example.test',
            null,
            '203.0.113.78',
        );
        $service->decide($report, ReportStatus::Upheld, 'The name has been reset.', $this->user('reply-none-curator@example.test', ['ROLE_CURATOR']));
        $statements->send($author->getEmail(), $author->getDisplayName(), 'en', null, new StatementOfReasons(
            StatementDecision::ChangedOrRemoved, StatementGround::Abuse, 'The name has been reset.', $report->getId()->toRfc4122(), fromReport: true,
        ));

        $sent = self::sent();
        self::assertCount(3, $sent);
        foreach ($sent as $email) {
            self::assertSame([], $email->getReplyTo(), (string) $email->getSubject());
            $body = strtolower((string) $email->getHtmlBody());
            self::assertStringNotContainsString('reply to this email', $body, (string) $email->getSubject());
        }
        self::assertStringContainsString('our contact page', strtolower((string) $sent[1]->getHtmlBody()));
        self::assertStringContainsString('contact page', strtolower((string) $sent[2]->getHtmlBody()));
        self::assertStringContainsString('https://cyclingcommons.example/contact', (string) $sent[2]->getHtmlBody());
        foreach ($sent as $email) {
            self::assertStringNotContainsString('court', strtolower((string) $email->getHtmlBody()), (string) $email->getSubject());
        }
    }
}
