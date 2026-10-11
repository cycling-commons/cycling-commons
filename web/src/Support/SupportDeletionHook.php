<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use App\Entity\User;
use App\Service\UserDeletionHookInterface;
use Doctrine\DBAL\Connection;

/**
 * Account deletion: what the rider filed stays, without their address.
 *
 * - A bug report the rider filed stays, because the fix helps everybody. It
 *   loses the account, the reply address and the address hash.
 * - A contact message the account sent stays for its own 24-month clock
 *   (ContactMessageRetention), as the record of what was asked, and loses
 *   the account, the address and the name now (owner 2026-10-11). The column
 *   takes no NULL, so the address is emptied; nothing mails a contact message
 *   after its first receipt.
 * - A content report keeps its DSA record and loses a reporter contact that
 *   is the account's address: such a report was filed signed in, with that
 *   address. Its reporter is then not told the outcome, which is the price of
 *   the erasure the rider asked for (owner 2026-10-11).
 * - What the account did as a curator (handling a bug report or a contact
 *   message, deciding a content report) stays and names nobody.
 *
 * `app:privacy:erasure-check` proves it daily on the live database.
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
        $this->db->executeStatement(
            "UPDATE contact_message SET email = '', name = NULL WHERE user_id = :u",
            ['u' => $id],
        );
        $this->db->executeStatement(
            'UPDATE content_report SET reporter_contact = NULL WHERE lower(reporter_contact) = lower(:e)',
            ['e' => $user->getEmail()],
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
