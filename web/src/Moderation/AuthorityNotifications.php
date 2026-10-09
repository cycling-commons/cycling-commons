<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\Entity\Submission;
use App\Entity\User;
use App\Media\Entity\MediaUpload;
use App\Media\MediaAction;
use App\Media\MediaEventLog;
use App\Service\AdminActionLogger;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * DSA Art. 18: the record that an administrator informed the competent
 * authority about a held photo or submission.
 *
 * Escalation hides and holds the material and alerts the administrators
 * ({@see \App\Media\MediaEscalationService}, {@see ModerationService::escalateSubmission()});
 * the administrator informs the authority outside the application and records
 * it here: when, who, which authority, and its reference if it gave one. The
 * record is written once per hold, by a conditional write in the database so
 * two administrators at once cannot overwrite each other, and a content-free line in the admin
 * action log keeps that it was made after the row is gone. Recording it on a submission records it on
 * the submission's held photos too, which are the same case. A held row with
 * no record after OVERDUE_HOURS is shown as overdue on /admin/escalated and
 * the admin dashboard.
 *
 * @see docs/specs/operations.md §7
 *
 * @api
 */
final class AuthorityNotifications
{
    /** A held row with no notification recorded this long after escalation is overdue. */
    public const int OVERDUE_HOURS = 24;

    /** Longest authority name or reference accepted. */
    public const int TEXT_MAX = 200;

    /**
     * Content-free audit actions, kept in the admin action log after the held
     * row itself is gone: the reference and the authority, never the material.
     */
    public const string ACTION_SUBMISSION = 'submission_authority_notified';
    public const string ACTION_PHOTO = 'photo_authority_notified';

    /** Clock difference tolerated before a notification time counts as in the future. */
    private const int FUTURE_SLACK_SECONDS = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MediaEventLog $events,
        private readonly AdminActionLogger $adminLog,
    ) {
    }

    /**
     * @throws \InvalidArgumentException with a translation key (art18.error.*)
     */
    public function recordPhoto(MediaUpload $upload, User $admin, string $authority, ?string $reference, ?\DateTimeImmutable $at = null): void
    {
        [$authority, $reference, $at] = $this->validate($authority, $reference, $at);
        if (!$upload->isEscalated()) {
            throw new \InvalidArgumentException('art18.error.not_held');
        }

        $adminId = (int) $admin->getId();
        $this->once(function () use ($upload, $adminId, $admin, $authority, $reference, $at): void {
            if (!$this->claim('media_upload', $upload->getId()->toRfc4122(), $at, $adminId, $authority, $reference)) {
                $this->em->refresh($upload);

                throw new \InvalidArgumentException('art18.error.already_recorded');
            }
            $upload->recordAuthorityNotice($at, $adminId, $authority, $reference);
            $this->events->append($upload->getId(), $adminId, MediaAction::AuthorityNotified, $this->note($authority, $reference, $at));
            $this->adminLog->log($admin, self::ACTION_PHOTO, null, \sprintf('photo %s · %s', $upload->getId()->toRfc4122(), $authority));
        });
    }

    /**
     * @throws \InvalidArgumentException with a translation key (art18.error.*)
     */
    public function recordSubmission(int $id, User $admin, string $authority, ?string $reference, ?\DateTimeImmutable $at = null): void
    {
        [$authority, $reference, $at] = $this->validate($authority, $reference, $at);
        $submission = $this->em->find(Submission::class, $id);
        if (null === $submission || !$submission->isEscalated()) {
            throw new \InvalidArgumentException('art18.error.not_held');
        }

        $adminId = (int) $admin->getId();
        $this->once(function () use ($submission, $id, $adminId, $admin, $authority, $reference, $at): void {
            if (!$this->claim('submission', (string) $id, $at, $adminId, $authority, $reference)) {
                $this->em->refresh($submission);

                throw new \InvalidArgumentException('art18.error.already_recorded');
            }
            $submission->recordAuthorityNotice($at, $adminId, $authority, $reference);
            foreach ($this->em->getRepository(MediaUpload::class)->findBy(['submissionId' => $id]) as $upload) {
                if ($upload->isEscalated() && $this->claim('media_upload', $upload->getId()->toRfc4122(), $at, $adminId, $authority, $reference)) {
                    $upload->recordAuthorityNotice($at, $adminId, $authority, $reference);
                    $this->events->append($upload->getId(), $adminId, MediaAction::AuthorityNotified, $this->note($authority, $reference, $at));
                }
            }
            // Content-free, like the escalation entry: the number and the authority.
            $this->adminLog->log($admin, self::ACTION_SUBMISSION, null, \sprintf('SUB-%d · %s', $id, $authority));
        });
    }

    /**
     * Write the record in the database only where none is: two administrators
     * recording at once both read "not recorded", and the second must not
     * overwrite the first. The row's own WHERE decides, not what was read.
     *
     * @return bool false when the row already holds a record, or is no longer held
     */
    private function claim(string $table, string $id, \DateTimeImmutable $at, int $adminId, string $authority, ?string $reference): bool
    {
        return 1 === (int) $this->em->getConnection()->executeStatement(
            \sprintf(
                'UPDATE %s SET authority_notified_at = :at, authority_notified_by_id = :admin, authority_name = :authority, authority_reference = :reference
                  WHERE id = CAST(:id AS %s) AND escalated_at IS NOT NULL AND authority_notified_at IS NULL',
                $table,
                'media_upload' === $table ? 'uuid' : 'bigint',
            ),
            ['at' => $at, 'admin' => $adminId, 'authority' => $authority, 'reference' => $reference, 'id' => $id],
            ['at' => Types::DATETIME_IMMUTABLE],
        );
    }

    /**
     * One transaction for the record, its history and its audit row. A
     * refusal rolls back on the connection, so the entity manager stays open
     * for the page that shows the refusal.
     *
     * @param callable(): void $write
     */
    private function once(callable $write): void
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $write();
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /** Held rows, photos and submissions, with no notification recorded past OVERDUE_HOURS. */
    public function overdueCount(?\DateTimeImmutable $now = null): int
    {
        $cutoff = self::cutoff($now ?? new \DateTimeImmutable());
        $count = 0;
        foreach ([MediaUpload::class, Submission::class] as $class) {
            $count += (int) $this->em->createQuery(
                'SELECT COUNT(r.id) FROM '.$class.' r
                  WHERE r.escalatedAt IS NOT NULL AND r.escalatedAt < :cutoff AND r.authorityNotifiedAt IS NULL',
            )->setParameter('cutoff', $cutoff)->getSingleScalarResult();
        }

        return $count;
    }

    public static function isOverdue(?\DateTimeImmutable $escalatedAt, ?\DateTimeImmutable $notifiedAt, ?\DateTimeImmutable $now = null): bool
    {
        return null !== $escalatedAt && null === $notifiedAt && $escalatedAt < self::cutoff($now ?? new \DateTimeImmutable());
    }

    private static function cutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('-%d hours', self::OVERDUE_HOURS));
    }

    /**
     * @return array{0: string, 1: ?string, 2: \DateTimeImmutable}
     */
    private function validate(string $authority, ?string $reference, ?\DateTimeImmutable $at): array
    {
        $authority = trim($authority);
        $reference = trim((string) $reference);
        if ('' === $authority) {
            throw new \InvalidArgumentException('art18.error.authority_required');
        }
        if (mb_strlen($authority) > self::TEXT_MAX || mb_strlen($reference) > self::TEXT_MAX) {
            throw new \InvalidArgumentException('art18.error.too_long');
        }
        $now = new \DateTimeImmutable();
        $at ??= $now;
        if ($at->getTimestamp() > $now->getTimestamp() + self::FUTURE_SLACK_SECONDS) {
            throw new \InvalidArgumentException('art18.error.future');
        }

        // Stored in the application's zone, as every other timestamp column is.
        return [$authority, '' === $reference ? null : $reference, $at->setTimezone(new \DateTimeZone(date_default_timezone_get()))];
    }

    private function note(string $authority, ?string $reference, \DateTimeImmutable $at): string
    {
        return \sprintf('%s · %s%s', $at->format('Y-m-d H:i'), $authority, null !== $reference ? ' · '.$reference : '');
    }
}
