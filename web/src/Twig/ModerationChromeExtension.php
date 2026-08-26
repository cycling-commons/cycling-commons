<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Moderation\ModerationScopeProvider;
use App\Moderation\SubmissionQueue;
use App\Translation\DecisionService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Open-queue counts for curator chrome (desk tabs and the account chip).
 *
 * @api
 */
final class ModerationChromeExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly DecisionService $decisions,
        private readonly SubmissionQueue $submissions,
        private readonly ModerationScopeProvider $scopes,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_translation_count', $this->pendingTranslationCount(...)),
            new TwigFunction('pending_submission_count', $this->pendingSubmissionCount(...)),
        ];
    }

    public function pendingTranslationCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted('ROLE_CURATOR')) {
            return 0;
        }

        return $this->decisions->pendingCount();
    }

    public function pendingSubmissionCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || !$this->security->isGranted('ROLE_CURATOR')) {
            return 0;
        }

        return $this->submissions->total($this->scopes->scopeFor($user));
    }
}
