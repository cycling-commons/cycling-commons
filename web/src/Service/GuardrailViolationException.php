<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown when an admin action is blocked by a safety guardrail.
 *
 * @see docs/specs/account-and-auth.md §6.4
 */
final class GuardrailViolationException extends \RuntimeException
{
}
