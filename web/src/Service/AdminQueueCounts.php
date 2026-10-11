<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Service;

use App\Community\CountryInterestService;
use App\Community\CuratorApplicationService;
use App\Media\MediaEscalationService;
use App\Moderation\ModerationService;
use App\Support\ReportStatus;
use App\Support\ReportTarget;
use App\Support\SupportRepository;

/**
 * What is waiting for somebody, for the admin dashboard: open country
 * requests, curator applications to decide, escalated submissions and photos,
 * and open reports. Live counts from the same helpers the desks use, so the
 * dashboard and the desk it links to cannot disagree.
 *
 * @see docs/specs/account-and-auth.md §6.6
 *
 * @api
 */
final readonly class AdminQueueCounts
{
    public function __construct(
        private CountryInterestService $countryRequests,
        private CuratorApplicationService $curatorApplications,
        private ModerationService $moderation,
        private MediaEscalationService $escalations,
        private SupportRepository $support,
    ) {
    }

    /**
     * @return array{countryRequests: int, countryReady: int, curatorApplications: int, escalatedSubmissions: int, escalatedPhotos: int, reports: int, photoReports: int}
     */
    public function collect(): array
    {
        return [
            'countryRequests' => $this->countryRequests->openCount(),
            // Riders in a live country who can be told it went live now.
            'countryReady' => array_sum($this->countryRequests->notifiable()),
            'curatorApplications' => $this->curatorApplications->pendingCount(),
            'escalatedSubmissions' => $this->moderation->heldSubmissionCount(),
            // Escalating a submission holds its photos too, so they count here as well.
            'escalatedPhotos' => $this->escalations->heldCount(),
            // Open and taken up, as the reports desk's Open chip counts them.
            'reports' => $this->support->openReportCount(),
            'photoReports' => $this->support->countReports(ReportStatus::Open, ReportTarget::Photo),
        ];
    }
}
