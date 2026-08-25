<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Translation\DecisionService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Pending translation count for moderator chrome (translations.md §5).
 *
 * @api
 */
final class ModerationChromeExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly DecisionService $decisions,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('pending_translation_count', $this->pendingTranslationCount(...)),
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
}
