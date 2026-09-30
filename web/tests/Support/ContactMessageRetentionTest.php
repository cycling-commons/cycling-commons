<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\ContactStatus;
use App\Support\ContactTopic;
use App\Support\Entity\ContactMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A contact message is deleted 24 months after its matter ended
 * (privacy.retention_mail, docs/specs/contact-and-support.md §4), through the
 * daily `app:media:gc`.
 *
 * The matter ends when a curator answers or closes the message. Its end is
 * read from `updated_at`, which every status change and note stamps, so it is
 * never earlier than the answer or the closing. A message still new or open
 * has not ended, however old it is.
 */
final class ContactMessageRetentionTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->em = $em;
    }

    public function testAnAnsweredOrClosedMessageOlderThanTwentyFourMonthsIsDeleted(): void
    {
        $answered = $this->message(ContactStatus::Answered, endedMonthsAgo: 25);
        $closed = $this->message(ContactStatus::Closed, endedMonthsAgo: 25);

        $display = $this->gc();

        self::assertFalse($this->exists($answered), 'an answered message past 24 months is gone');
        self::assertFalse($this->exists($closed), 'a closed message past 24 months is gone');
        self::assertMatchesRegularExpression('/deleted 2 expired contact message/', $display);
    }

    public function testAMessageEndedTwentyThreeMonthsAgoIsKept(): void
    {
        $recent = $this->message(ContactStatus::Answered, endedMonthsAgo: 23);

        $this->gc();

        self::assertTrue($this->exists($recent));
    }

    /** New or open means nobody has answered yet: the matter has not ended, so no clock runs. */
    public function testAMessageStillWaitingIsKeptHoweverOld(): void
    {
        $new = $this->message(ContactStatus::New, endedMonthsAgo: 30);
        $open = $this->message(ContactStatus::Open, endedMonthsAgo: 30);

        $this->gc();

        self::assertTrue($this->exists($new));
        self::assertTrue($this->exists($open));
    }

    /** Answered long ago, then opened again: it is waiting once more, and the old answer does not count. */
    public function testAReopenedMessageIsKept(): void
    {
        $reopened = $this->message(ContactStatus::Answered, endedMonthsAgo: 30);
        $this->em->getConnection()->executeStatement(
            "UPDATE contact_message SET status = 'open', updated_at = NOW() WHERE id = ?",
            [$reopened],
        );

        $this->gc();

        self::assertTrue($this->exists($reopened));
    }

    public function testASecondRunDeletesNothing(): void
    {
        $this->message(ContactStatus::Closed, endedMonthsAgo: 26);

        self::assertMatchesRegularExpression('/deleted 1 expired contact message/', $this->gc());
        self::assertMatchesRegularExpression('/deleted 0 expired contact message/', $this->gc());
    }

    private function message(ContactStatus $status, int $endedMonthsAgo): int
    {
        $message = new ContactMessage(ContactTopic::Question, 'sender@example.org', 'Retention test.');
        $message->setName('Retention Sender');
        $message->setStatus($status);
        $this->em->persist($message);
        $this->em->flush();
        $id = (int) $message->getId();

        $ended = (new \DateTimeImmutable())->modify(\sprintf('-%d months', $endedMonthsAgo));
        $this->em->getConnection()->executeStatement(
            'UPDATE contact_message SET created_at = ?, updated_at = ?, answered_at = CASE WHEN answered_at IS NULL THEN NULL ELSE ?::timestamp END WHERE id = ?',
            [
                $ended->modify('-1 day')->format('Y-m-d H:i:s'),
                $ended->format('Y-m-d H:i:s'),
                $ended->format('Y-m-d H:i:s'),
                $id,
            ],
        );
        $this->em->clear();

        return $id;
    }

    private function exists(int $id): bool
    {
        return false !== $this->em->getConnection()->fetchOne('SELECT 1 FROM contact_message WHERE id = ?', [$id]);
    }

    private function gc(): string
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:media:gc'));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());

        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
