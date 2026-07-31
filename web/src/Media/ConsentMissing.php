<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

/**
 * No valid consent backs this request. Thrown wherever consent is asserted and
 * absent, expired by a version bump, or owned by somebody else — the one
 * outcome every ambiguity resolves to (docs/specs/photo-uploads.md §4
 * fail-closed).
 *
 * @api Thrown by ConsentService::assertValid().
 */
final class ConsentMissing extends \RuntimeException
{
}
