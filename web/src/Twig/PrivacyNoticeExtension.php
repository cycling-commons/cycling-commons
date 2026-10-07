<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Legal\PrivacyNoticeVersions;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cc_privacy_unseen()`: the current privacy notice version when the signed-in
 * rider has not seen it yet, else null (docs/specs/privacy-notice.md). A visitor
 * who is not signed in never gets it: their pages may sit in a shared cache.
 *
 * @api
 */
final class PrivacyNoticeExtension extends AbstractExtension
{
    public function __construct(private readonly Security $security)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('cc_privacy_unseen', $this->unseen(...))];
    }

    /** @return array{number: int, date: string, changes: list<string>}|null */
    public function unseen(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || ($user->getPrivacyVersionSeen() ?? 0) >= PrivacyNoticeVersions::CURRENT) {
            return null;
        }

        return PrivacyNoticeVersions::current();
    }
}
