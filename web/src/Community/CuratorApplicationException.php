<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Community;

/**
 * Machine-readable domain failures out of CuratorApplicationService, on the
 * same footing as InvalidNoteException: a controller maps `reason` to a
 * translated key instead of parsing (or verbatim rendering) English prose,
 * so the join page and the admin review page stay correct across all four
 * locales.
 */
final class CuratorApplicationException extends \DomainException
{
    /** @param 'already_pending'|'not_onboarded'|'osm_handle_too_long'|'social_url_invalid'|'applicant_gone'|'already_decided'|'region_gone'|'already_global_curator' $reason */
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
