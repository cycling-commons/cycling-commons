<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\MessageMailer;
use App\Messaging\MessageOutbox;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use App\Moderation\MissingReasonException;
use App\Moderation\ModerationService;
use App\Moderation\StatementDecision;
use App\Moderation\StatementGround;
use App\Moderation\StatementOfReasons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

/**
 * A rejected or trashed submission tells its rider why, with every element
 * DSA Article 17(3) lists, in their messages and by email; spam does not.
 *
 * @see docs/specs/content-reports.md §7
 */
final class SubmissionStatementTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testARejectionWithoutANoteIsRefusedAndChangesNothing(): void
    {
        $rider = $this->user('rider');
        $sub = $this->submission($rider, 'Col du Silence');

        try {
            $this->moderation()->decide((int) $sub->getId(), 'reject', $this->user('curator'), '   ');
            self::fail('a rejection without a note went through');
        } catch (MissingReasonException $e) {
            self::assertSame('dsa_statement.desk.reject_note_required', $e->getMessage());
        }

        $this->em->clear();
        self::assertSame(SubmissionStatus::Pending, $this->em->find(Submission::class, $sub->getId())?->getStatus());
        self::assertSame([], $this->messagesFor($rider));
    }

    public function testARejectionCarriesItsStatementAndTheEmailIsTheStatement(): void
    {
        $rider = $this->user('rider');
        $sub = $this->submission($rider, 'Col du Refus');

        $this->moderation()->decide((int) $sub->getId(), 'reject', $this->user('curator'), 'The tap is 200 m further on.');
        $this->deliver();

        $messages = $this->messagesFor($rider);
        self::assertCount(1, $messages);
        self::assertSame(UserMessageKind::SubmissionRejected, $messages[0]->getKind());
        $statement = StatementOfReasons::fromArray($messages[0]->getBodyParams()[StatementOfReasons::PARAM] ?? null);
        self::assertNotNull($statement);
        self::assertSame(StatementDecision::NotPublished, $statement->decision);
        self::assertSame(StatementGround::NotAccepted, $statement->ground);
        self::assertSame('The tap is 200 m further on.', $statement->facts);
        self::assertSame('SUB-'.$sub->getId(), $statement->reference);
        self::assertSame('Col du Refus', $statement->subject);
        self::assertFalse($statement->fromReport);
        self::assertFalse($statement->automated);

        self::assertCount(1, self::getMailerMessages());
        $mail = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame($rider->getEmail(), $mail->getTo()[0]->getAddress());
        self::assertNotEmpty($mail->getReplyTo(), 'a reply reaches us');
        $html = (string) $mail->getHtmlBody();
        foreach (['Col du Refus', 'The tap is 200 m further on.', 'A curator made this decision.', 'not a law', 'reply to this email', 'SUB-'.$sub->getId(), 'Article 17'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
    }

    public function testTrashAsSpamTellsNobody(): void
    {
        $rider = $this->user('rider');
        $sub = $this->submission($rider, 'Cheap bikes here');

        $this->moderation()->trashSubmission((int) $sub->getId(), $this->user('curator'), StatementGround::Spam, null);
        $this->deliver();

        self::assertSame([], $this->messagesFor($rider));
        self::assertCount(0, self::getMailerMessages());
    }

    public function testTrashAsAbuseNeedsTheFacts(): void
    {
        $sub = $this->submission($this->user('rider'), 'Insult');

        $this->expectException(MissingReasonException::class);
        $this->moderation()->trashSubmission((int) $sub->getId(), $this->user('curator'), StatementGround::Abuse, '  ');
    }

    public function testTrashAsAbuseSendsAStatementThatTheBinDoesNotHide(): void
    {
        $rider = $this->user('rider');
        $sub = $this->submission($rider, 'Name and shame');

        $this->moderation()->trashSubmission((int) $sub->getId(), $this->user('curator'), StatementGround::Abuse, 'It names a neighbour and calls him a thief.');
        $this->deliver();

        $messages = $this->messagesFor($rider);
        self::assertCount(1, $messages, 'the statement is in their messages although the thread went to Trash');
        self::assertSame(UserMessageKind::StatementOfReasons, $messages[0]->getKind());
        self::assertSame(MessageService::STATEMENT_CHANNEL, $messages[0]->getChannel());
        $statement = StatementOfReasons::fromArray($messages[0]->getBodyParams()[StatementOfReasons::PARAM] ?? null);
        self::assertNotNull($statement);
        self::assertSame(StatementDecision::Removed, $statement->decision);
        self::assertSame(StatementGround::Abuse, $statement->ground);
        self::assertSame('dsa_statement.facts.trashed', $statement->factsKey);
        self::assertCount(1, self::getMailerMessages());
    }

    public function testADeletedAccountHearsNothingAndTheDecisionStands(): void
    {
        $rider = $this->user('rider');
        $sub = $this->submission($rider, 'Orphan');
        $this->em->getConnection()->executeStatement('UPDATE submission SET user_id = NULL WHERE id = ?', [$sub->getId()]);
        $this->em->clear();

        $this->moderation()->decide((int) $sub->getId(), 'reject', $this->user('curator'), 'Not a place.');
        $this->moderation()->trashSubmission((int) $sub->getId(), $this->user('curator'), StatementGround::Abuse, 'Abuse.');

        self::assertTrue((bool) $this->em->find(Submission::class, $sub->getId())?->isTrashed());
    }

    private function moderation(): ModerationService
    {
        return static::getContainer()->get(ModerationService::class);
    }

    private function deliver(): void
    {
        $outbox = static::getContainer()->get(MessageOutbox::class);
        $mailer = static::getContainer()->get(MessageMailer::class);
        foreach ($outbox->drain() as $message) {
            $mailer->deliver($message);
        }
    }

    /** @return list<UserMessage> */
    private function messagesFor(User $user): array
    {
        /** @var list<UserMessage> $rows */
        $rows = $this->em->getRepository(UserMessage::class)->findBy(['userId' => $user->getId(), 'trashedAt' => null], ['id' => 'ASC']);

        return $rows;
    }

    private function submission(User $rider, string $title): Submission
    {
        $sub = (new Submission())->setType(SubmissionType::Edit)->setLetter('D')->setUserId((int) $rider->getId())
            ->setTitle($title)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $this->em->persist($sub);
        $this->em->flush();

        return $sub;
    }

    private function user(string $kind): User
    {
        $u = (new User())->setEmail($kind.'-'.uniqid('', true).'@statement.test')->setDisplayName(ucfirst($kind));
        $u->setPassword('x');
        if ('curator' === $kind) {
            $u->setRoles(['ROLE_CURATOR']);
        }
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }
}
