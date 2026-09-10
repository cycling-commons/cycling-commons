<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
