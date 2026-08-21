<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

/**
 * Domain failure from CuratorApplicationService; `reason` maps to a translation key.
 */
final class CuratorApplicationException extends \DomainException
{
    /** @param 'already_pending'|'not_onboarded'|'osm_handle_too_long'|'social_url_invalid'|'applicant_gone'|'already_decided'|'region_gone'|'already_global_curator' $reason */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
