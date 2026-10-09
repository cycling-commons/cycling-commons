<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaConsent;
use App\Media\MediaEscalationService;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use App\Moderation\AuthorityNotifications;
use App\Moderation\ModerationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * DSA Art. 18: an administrator records on the held row that the competent
 * authority was informed, and a held row without that record is overdue after
 * AuthorityNotifications::OVERDUE_HOURS (docs/specs/operations.md §7).
 */
final class AuthorityNotificationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AuthorityNotifications $notices;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->notices = static::getContainer()->get(AuthorityNotifications::class);
    }

    private function user(string $name): User
    {
        $user = (new User())->setEmail($name.'-'.uniqid().'@example.test');
        $user->setPassword('x');
        $user->setDisplayName($name);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function photo(User $owner, ?int $submissionId = null): MediaUpload
    {
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $this->em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 900, 600, 4242, bucket: 'test-bucket-eu-01');
        if (null !== $submissionId) {
            $upload->claim($submissionId);
        }
        $this->em->persist($upload);
        $this->em->flush();
        static::getContainer()->get(MediaStorage::class)
            ->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 900, 600));

        return $upload;
    }

    private function heldPhoto(): MediaUpload
    {
        $upload = $this->photo($this->user('owner'));
        static::getContainer()->get(MediaEscalationService::class)->escalate($upload, $this->user('curator'), 'A threat against a named person.');

        return $upload;
    }

    private function heldSubmission(): Submission
    {
        $sub = (new Submission())->setType(SubmissionType::NewItem)->setLetter('N')
            ->setUserId((int) $this->user('author')->getId())
            ->setTitle('Art 18 fixture')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $this->em->persist($sub);
        $this->em->flush();

        return $sub;
    }

    /** Pushes the escalation back in time, as if it had been waiting that long. */
    private function age(string $table, string $id, int $hours): void
    {
        static::getContainer()->get(Connection::class)->executeStatement(
            \sprintf('UPDATE %s SET escalated_at = NOW() - make_interval(hours => :h) WHERE id = CAST(:id AS %s)', $table, 'media_upload' === $table ? 'uuid' : 'bigint'),
            ['h' => $hours, 'id' => $id],
        );
        $this->em->clear();
    }

    public function testRecordingOnAHeldPhotoKeepsWhenWhoWhichAuthorityAndReference(): void
    {
        $upload = $this->heldPhoto();
        $admin = $this->user('admin');
        $at = new \DateTimeImmutable('-2 hours');

        $this->notices->recordPhoto($upload, $admin, '  Politie (NL), 0900-8844  ', ' PL0900-2026-123 ', $at);

        self::assertSame($at->format('Y-m-d H:i'), $upload->getAuthorityNotifiedAt()?->format('Y-m-d H:i'));
        self::assertSame($admin->getId(), $upload->getAuthorityNotifiedById());
        self::assertSame('Politie (NL), 0900-8844', $upload->getAuthorityName());
        self::assertSame('PL0900-2026-123', $upload->getAuthorityReference());

        $event = static::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT actor_id, note FROM media_moderation_event WHERE media_id = :m AND action = :a',
            ['m' => $upload->getId()->toRfc4122(), 'a' => MediaAction::AuthorityNotified],
        );
        self::assertIsArray($event, 'the photo history records the notification');
        self::assertSame($admin->getId(), (int) $event['actor_id']);
        self::assertStringContainsString('Politie (NL)', (string) $event['note']);

        self::assertSame(
            \sprintf('photo %s · Politie (NL), 0900-8844', $upload->getId()->toRfc4122()),
            static::getContainer()->get(Connection::class)->fetchOne(
                'SELECT note FROM admin_action_log WHERE action = :a AND actor_id = :u',
                ['a' => AuthorityNotifications::ACTION_PHOTO, 'u' => $admin->getId()],
            ),
            'the audit keeps that it was made, beyond the row',
        );
    }

    public function testTheReferenceIsOptionalAndTheTimeDefaultsToNow(): void
    {
        $upload = $this->heldPhoto();

        $this->notices->recordPhoto($upload, $this->user('admin'), 'Europol', '');

        self::assertNull($upload->getAuthorityReference());
        self::assertNotNull($upload->getAuthorityNotifiedAt());
        self::assertLessThan(60, abs(time() - $upload->getAuthorityNotifiedAt()->getTimestamp()));
    }

    public function testItIsRefusedWithoutAnAuthorityOnAPhotoThatIsNotHeldTwiceOrInTheFuture(): void
    {
        $admin = $this->user('admin');
        $upload = $this->heldPhoto();

        foreach ([
            'art18.error.authority_required' => fn () => $this->notices->recordPhoto($upload, $admin, '   ', null),
            'art18.error.too_long' => fn () => $this->notices->recordPhoto($upload, $admin, str_repeat('x', AuthorityNotifications::TEXT_MAX + 1), null),
            'art18.error.future' => fn () => $this->notices->recordPhoto($upload, $admin, 'Politie', null, new \DateTimeImmutable('+1 day')),
            'art18.error.not_held' => fn () => $this->notices->recordPhoto($this->photo($this->user('owner')), $admin, 'Politie', null),
        ] as $key => $call) {
            try {
                $call();
                self::fail($key.' was not refused');
            } catch (\InvalidArgumentException $e) {
                self::assertSame($key, $e->getMessage());
            }
        }

        $this->notices->recordPhoto($upload, $admin, 'Politie', null);
        try {
            $this->notices->recordPhoto($upload, $admin, 'Another', null);
            self::fail('a second record was accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('art18.error.already_recorded', $e->getMessage());
        }
        self::assertSame('Politie', $upload->getAuthorityName(), 'the record is written once');
    }

    /**
     * Two administrators record at once: the second one's page was loaded
     * before the first saved, so its row still reads "not recorded". The
     * write is conditional in the database, and the second is refused.
     */
    public function testASecondAdministratorRecordingAtTheSameTimeDoesNotOverwriteTheFirst(): void
    {
        $db = static::getContainer()->get(Connection::class);
        $upload = $this->heldPhoto();
        $first = $this->user('first');
        $db->executeStatement(
            "UPDATE media_upload SET authority_notified_at = NOW(), authority_notified_by_id = :a, authority_name = 'First authority' WHERE id = :id",
            ['a' => $first->getId(), 'id' => $upload->getId()->toRfc4122()],
        );
        self::assertNull($upload->getAuthorityNotifiedAt(), 'this copy was read before the first record');

        try {
            $this->notices->recordPhoto($upload, $this->user('second'), 'Second authority', null);
            self::fail('the second record overwrote the first');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('art18.error.already_recorded', $e->getMessage());
        }
        self::assertSame('First authority', $db->fetchOne('SELECT authority_name FROM media_upload WHERE id = ?', [$upload->getId()->toRfc4122()]));
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM admin_action_log WHERE action = ? AND note LIKE ?', [AuthorityNotifications::ACTION_PHOTO, '%'.$upload->getId()->toRfc4122().'%']));

        $sub = $this->heldSubmission();
        $id = (int) $sub->getId();
        $photo = $this->photo($this->user('owner'), $id);
        static::getContainer()->get(ModerationService::class)->escalateSubmission($id, $this->user('curator'), 'Words that threaten a person.');
        $this->em->find(Submission::class, $id);
        $db->executeStatement(
            "UPDATE submission SET authority_notified_at = NOW(), authority_notified_by_id = :a, authority_name = 'First authority' WHERE id = :id",
            ['a' => $first->getId(), 'id' => $id],
        );

        try {
            $this->notices->recordSubmission($id, $this->user('second'), 'Second authority', null);
            self::fail('the second record overwrote the first');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('art18.error.already_recorded', $e->getMessage());
        }
        self::assertSame('First authority', $db->fetchOne('SELECT authority_name FROM submission WHERE id = ?', [$id]));
        self::assertNull($db->fetchOne('SELECT authority_name FROM media_upload WHERE id = ?', [$photo->getId()->toRfc4122()]), 'nor did it write on the photos');
    }

    public function testRecordingOnASubmissionCoversItsHeldPhotosAndIsAudited(): void
    {
        $sub = $this->heldSubmission();
        $id = (int) $sub->getId();
        $photo = $this->photo($this->user('owner'), $id);
        static::getContainer()->get(ModerationService::class)->escalateSubmission($id, $this->user('curator'), 'Words that threaten a person.');
        $admin = $this->user('admin');

        $this->notices->recordSubmission($id, $admin, 'Police fédérale (BE)', 'BE-77');
        $this->em->clear();

        $sub = $this->em->find(Submission::class, $id);
        $photo = $this->em->find(MediaUpload::class, $photo->getId());
        self::assertNotNull($sub);
        self::assertNotNull($photo);
        self::assertSame('Police fédérale (BE)', $sub->getAuthorityName());
        self::assertSame('BE-77', $sub->getAuthorityReference());
        self::assertSame($admin->getId(), $sub->getAuthorityNotifiedById());
        self::assertSame('Police fédérale (BE)', $photo->getAuthorityName(), 'its photos are the same case');

        $note = static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT note FROM admin_action_log WHERE action = :a AND actor_id = :u',
            ['a' => AuthorityNotifications::ACTION_SUBMISSION, 'u' => $admin->getId()],
        );
        self::assertSame(\sprintf('SUB-%d · Police fédérale (BE)', $id), $note);
    }

    public function testAHeldRowWithoutARecordIsOverdueAfterTheSetTime(): void
    {
        $before = $this->notices->overdueCount();
        $fresh = $this->heldPhoto();
        $old = $this->heldPhoto();
        $sub = $this->heldSubmission();
        static::getContainer()->get(ModerationService::class)->escalateSubmission((int) $sub->getId(), $this->user('curator'), 'Threat.');

        self::assertSame($before, $this->notices->overdueCount(), 'nothing is overdue at once');

        $this->age('media_upload', $old->getId()->toRfc4122(), AuthorityNotifications::OVERDUE_HOURS + 1);
        $this->age('submission', (string) $sub->getId(), AuthorityNotifications::OVERDUE_HOURS + 1);
        self::assertSame($before + 2, $this->notices->overdueCount());

        $cards = array_column(static::getContainer()->get(MediaEscalationService::class)->held(1, 500), null, 'uuid');
        self::assertTrue($cards[$old->getId()->toRfc4122()]['overdue']);
        self::assertFalse($cards[$fresh->getId()->toRfc4122()]['overdue']);
        $subs = array_column(static::getContainer()->get(ModerationService::class)->heldSubmissions(1, 500), null, 'id');
        self::assertTrue($subs[(int) $sub->getId()]['overdue']);

        $old = $this->em->find(MediaUpload::class, $old->getId());
        self::assertNotNull($old);
        $this->notices->recordPhoto($old, $this->user('admin'), 'Politie', null);
        $this->notices->recordSubmission((int) $sub->getId(), $this->user('admin'), 'Politie', null);
        self::assertSame($before, $this->notices->overdueCount(), 'a record ends it');

        $cards = array_column(static::getContainer()->get(MediaEscalationService::class)->held(1, 500), null, 'uuid');
        self::assertFalse($cards[$old->getId()->toRfc4122()]['overdue']);
        self::assertSame('Politie', $cards[$old->getId()->toRfc4122()]['authority']);
        self::assertSame('admin', $cards[$old->getId()->toRfc4122()]['notifiedBy']);
    }

    public function testANewEscalationAfterAReleaseStartsANewCase(): void
    {
        $upload = $this->heldPhoto();
        $admin = $this->user('admin');
        $this->notices->recordPhoto($upload, $admin, 'Politie', 'REF-1');
        static::getContainer()->get(MediaEscalationService::class)->release($upload, $admin);

        self::assertSame('Politie', $upload->getAuthorityName(), 'a release keeps the record');

        static::getContainer()->get(MediaEscalationService::class)->escalate($upload, $this->user('curator'), 'Again, something new.');
        self::assertNull($upload->getAuthorityNotifiedAt());
        self::assertNull($upload->getAuthorityName());
        self::assertNull($upload->getAuthorityReference());
        self::assertNull($upload->getAuthorityNotifiedById());
    }
}
