<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Entity\User;
use App\Moderation\Entity\UnsentStatement;
use App\Service\AdminActionLogger;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The account statements of reasons whose email did not go out, and what an
 * administrator does about them (DSA Article 17(1)(c)).
 *
 * An account decision is explained by email alone. When the transport
 * refuses it, the statement is kept whole ({@see UnsentStatement}), the
 * administrator who decided is told at once, and the list under Unsent
 * statements in the admin desk offers Send again and Discard. A statement
 * that goes out leaves the list. Both actions are audited, by reference and
 * never by address.
 *
 * @see docs/specs/account-and-auth.md §6.8
 *
 * @api
 */
final readonly class UnsentStatements
{
    public const string ACTION_RESENT = 'statement_resent';
    public const string ACTION_DISCARDED = 'statement_discarded';

    public function __construct(
        private EntityManagerInterface $em,
        private StatementOfReasonsMailer $statements,
        private AdminActionLogger $adminLog,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Email the statement; when it does not go, keep it for an administrator.
     *
     * @param int|null $userId the account while it exists; null after a removal
     *
     * @return bool whether the mail was handed to the transport
     */
    public function send(?int $userId, string $address, string $name, ?string $locale, ?string $timeZone, StatementOfReasons $statement): bool
    {
        if ($this->statements->send($address, $name, $locale, $timeZone, $statement)) {
            return true;
        }
        // Written on its own, not by a flush: after a removal the unit of
        // work still holds the deleted account, and nothing else is to be saved.
        $now = $this->clock->now();
        $this->em->getConnection()->insert('unsent_statement', [
            'user_id' => $userId,
            'address' => $address,
            'name' => mb_substr($name, 0, 100),
            'locale' => $locale,
            'time_zone' => $timeZone,
            'statement' => $statement->toArray(),
            'created_at' => $now,
            'attempts' => 1,
            'last_attempt_at' => $now,
        ], [
            'statement' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'last_attempt_at' => Types::DATETIME_IMMUTABLE,
        ]);

        return false;
    }

    /**
     * Try once more. Delivered, it leaves the list; refused again, it stays
     * with one more attempt counted.
     *
     * @throws \InvalidArgumentException when there is no such unsent statement
     */
    public function resend(int $id, User $admin): bool
    {
        $row = $this->find($id);
        $statement = $row->statement();
        if (null !== $statement && $this->statements->send($row->getAddress(), $row->getName(), $row->getLocale(), $row->getTimeZone(), $statement)) {
            $this->em->remove($row);
            $this->adminLog->log($admin, self::ACTION_RESENT, null, $statement->reference);

            return true;
        }
        $row->failedAgain($this->clock->now());
        $this->em->flush();

        return false;
    }

    /**
     * Give up on it, when the address can no longer be reached. The audit
     * row keeps the reference, so the decision can still be traced.
     *
     * @throws \InvalidArgumentException when there is no such unsent statement
     */
    public function discard(int $id, User $admin): void
    {
        $row = $this->find($id);
        $this->em->remove($row);
        $this->adminLog->log($admin, self::ACTION_DISCARDED, null, $row->statement()->reference ?? 'unsent #'.$id);
    }

    /** @return list<UnsentStatement> oldest first */
    public function all(): array
    {
        return $this->em->getRepository(UnsentStatement::class)->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function count(): int
    {
        return $this->em->getRepository(UnsentStatement::class)->count([]);
    }

    private function find(int $id): UnsentStatement
    {
        $row = $this->em->find(UnsentStatement::class, $id);
        if (null === $row) {
            throw new \InvalidArgumentException('account_suspension.unsent.gone');
        }

        return $row;
    }
}
