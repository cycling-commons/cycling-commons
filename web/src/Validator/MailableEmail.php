<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * An address the mailer can send to.
 *
 * `Assert\Email` in its default mode accepts `j..t@gmail.com`, which
 * `new Address()` then refuses with an exception: on sign-up that was a 500
 * after the row was written (GlitchTip, 2026-09-29). This builds the Address
 * the mailer would, so the two can never disagree again.
 *
 * @see docs/specs/account-and-auth.md §2
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class MailableEmail extends Constraint
{
    public string $message = 'form.error_email_mailable';
}
