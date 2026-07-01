<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown when an admin action is blocked by a safety guardrail
 * (self-lockout or removing the last remaining administrator).
 */
final class GuardrailViolationException extends \RuntimeException
{
}
