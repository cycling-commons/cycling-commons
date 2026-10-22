<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Catalog\Entity\Submission;
use App\Catalog\RiderPseudonym;

/**
 * The name a person reads and quotes for a submission: `#` and its code
 * (`submission.code`, `#k7m2x9qa`), on receipts, messages, the rider's list
 * and the curators' desk. The running id stays inside: links, the admin log
 * and the command line keep `SUB-<id>`, and the public API its
 * `submission_id`. Old messages keep the `SUB-<id>` they were sent with,
 * and the desk search reads both.
 *
 * @see docs/specs/moderation-and-contribution.md §3.1
 *
 * @api
 */
final class SubmissionRef
{
    public const string PREFIX = '#';

    public static function label(string $code): string
    {
        return self::PREFIX.$code;
    }

    public static function of(Submission $submission): string
    {
        $code = $submission->getCode();
        if (null === $code) {
            throw new \LogicException('A submission has its code once it is inserted.');
        }

        return self::label($code);
    }

    /** The code a typed `#k7m2x9qa` or `k7m2x9qa` names, lower-cased, or null. */
    public static function codeIn(string $typed): ?string
    {
        $code = strtolower(ltrim(trim($typed), self::PREFIX.' '));

        return RiderPseudonym::LENGTH === \strlen($code) && RiderPseudonym::isValid($code) ? $code : null;
    }
}
