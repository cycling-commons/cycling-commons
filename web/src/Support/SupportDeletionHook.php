<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;
use Doctrine\DBAL\Connection;

/**
 * Account deletion: bug reports stay without their reporter; support rows lose
 * the account.
 *
 * - A bug report the rider filed stays, because the fix helps everybody. It
 *   loses the account, the reply address and the address hash.
 * - A contact message keeps the address the sender typed until its own
 *   24-month clock deletes the whole row (ContactMessageRetention); it loses
 *   the account link now.
 * - A content report keeps its reporter's address until 90 days after its
 *   decision (ReportContactRetention), so the reporter is still told the
 *   outcome the law owes them (DSA Art. 16(5)), also after deleting the
 *   account they filed it from (owner 2026-10-11, privacy notice v3).
 * - What the account did as a curator (handling a bug report or a contact
 *   message, deciding a content report) stays and names nobody.
 *
 * `app:privacy:erasure-check` proves daily, on the live database, that the
 * address is left nowhere else, and that those two clocks remove it.
 *
 * @see docs/specs/account-and-auth.md §6.3
 * @see docs/specs/contact-and-support.md §4, §5
 *
 * @api
 */
final class SupportDeletionHook implements UserDeletionHookInterface
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[\Override]
    public function preDelete(User $user): void
    {
        $id = (int) $user->getId();

        $this->db->executeStatement(
            'UPDATE bug_report SET user_id = NULL, reporter_email = NULL, ip_hash = NULL WHERE user_id = :u',
            ['u' => $id],
        );
        foreach ([
            ['bug_report', 'handled_by_user_id'],
            ['contact_message', 'user_id'],
            ['contact_message', 'handled_by_user_id'],
            ['content_report', 'decided_by_id'],
        ] as [$table, $column]) {
            $this->db->executeStatement(\sprintf('UPDATE %1$s SET %2$s = NULL WHERE %2$s = :u', $table, $column), ['u' => $id]);
        }
    }
}
