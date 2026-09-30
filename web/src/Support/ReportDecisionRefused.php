<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

/**
 * A decision the reports desk will not record, and why.
 *
 * The message is a translation key the desk shows next to the status field.
 * Thrown before anything is saved or sent, so a refused decision leaves the
 * report, the photo and every mailbox exactly as they were.
 *
 * @see docs/specs/content-reports.md §9
 *
 * @api
 */
final class ReportDecisionRefused extends \DomainException
{
}
