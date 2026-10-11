<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support\Message;

/**
 * Mail the support address the reports that arrived while the report alert's
 * window was closed ({@see \App\Support\ReportAlert}). Queued with a delay to
 * the window's end; it carries nothing, because the reports are read when it
 * runs.
 *
 * @see docs/specs/contact-and-support.md §7
 *
 * @api
 */
final readonly class SendReportDigest
{
}
