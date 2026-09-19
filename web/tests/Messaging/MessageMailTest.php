<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Entity\User;
use App\Messaging\MessageMailer;
use App\Messaging\MessageOutbox;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

/**
 * M7 — a dashboard message reaches the recipient's inbox.
 *
 * @internal
 */
final class MessageMailTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    private function user(string $email, ?string $locale = null): User
    {
        $u = new User();
        $u->setEmail($email);
        $u->setPassword('x');
        $u->setDisplayName('Test Rider');
        if (null !== $locale) {
            $u->setLocale($locale);
        }
        $this->em()->persist($u);
        $this->em()->flush();

        return $u;
    }

    /** Drain the outbox the way the terminate subscriber does. */
    private function deliverQueued(): void
    {
        /** @var MessageOutbox $outbox */
        $outbox = self::getContainer()->get(MessageOutbox::class);
        /** @var MessageMailer $mailer */
        $mailer = self::getContainer()->get(MessageMailer::class);
        foreach ($outbox->drain() as $message) {
            $mailer->deliver($message);
        }
    }

    public function testAnApprovalIsEmailedToTheRider(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(MessageService::class);
        $rider = $this->user('mail-rider@test.test');

        $svc->sendSystem(
            $rider->getId(),
            UserMessageKind::SubmissionApproved,
            'submission',
            1,
            'SUB-1',
            'messages.body.submission_approved',
            ['%title%' => 'Col du Test'],
        );
        // sendSystem deliberately does not flush — the decision transaction
        // does. Without an id the mailer refuses to send, which is the
        // rollback guard, so the test has to commit first.
        $this->em()->flush();
        $this->deliverQueued();

        self::assertCount(1, self::getMailerMessages());
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertStringContainsString('mail-rider@test.test', $mail->getTo()[0]->getAddress());
        self::assertStringContainsString('Contribution approved', (string) $mail->getSubject());
        $body = (string) $mail->getHtmlBody();
        self::assertStringContainsString('Col du Test', $body, 'the body line is rendered with its params');
        self::assertStringContainsString('/account/messages', $body, 'and it links to the dashboard');
    }

    /**
     * The rollback guard, which is the whole reason sends are queued rather
     * than made inside the decision transaction.
     */
    public function testAMessageThatWasNeverCommittedIsNotEmailed(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(MessageService::class);
        $rider = $this->user('mail-rollback@test.test');

        $svc->sendSystem(
            $rider->getId(),
            UserMessageKind::SubmissionApproved,
            'submission',
            2,
            'SUB-2',
            'messages.body.submission_approved',
            ['%title%' => 'Never happened'],
        );
        // No flush: the row has no id, exactly as after a rolled-back decision.
        $this->deliverQueued();

        self::assertCount(0, self::getMailerMessages());
    }

    /** The recipient's language, not whoever triggered the decision. */
    public function testTheEmailIsInTheRecipientsLocale(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(MessageService::class);
        $rider = $this->user('mail-es@test.test', 'es');

        $svc->sendSystem(
            $rider->getId(),
            UserMessageKind::SubmissionNeedsInfo,
            'submission',
            3,
            'SUB-3',
            'messages.body.submission_needs_info',
            ['%title%' => 'Puerto de Prueba'],
            '¿Por qué lado empieza?',
        );
        $this->em()->flush();
        $this->deliverQueued();

        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertStringContainsString('curador', (string) $mail->getSubject(), 'subject is Spanish');
        self::assertStringContainsString('¿Por qué lado empieza?', (string) $mail->getHtmlBody(),
            "the curator's own question travels — on a needs-info it IS the message");
        self::assertStringContainsString('/es/account/messages', (string) $mail->getHtmlBody());
    }

    /** A rider's reply is desk work; it must not mail the curator. */
    public function testRiderRepliesAreNotEmailed(): void
    {
        self::bootKernel();
        $svc = self::getContainer()->get(MessageService::class);
        $curator = $this->user('mail-curator@test.test');
        $rider = $this->user('mail-replier@test.test');

        $svc->sendRiderReply($curator->getId(), $rider->getId(), 'submission', 4, 'SUB-4', 'the north side');
        $this->deliverQueued();

        self::assertCount(0, self::getMailerMessages());
    }
}
