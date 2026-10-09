<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Legal\LegalVersions;
use App\Legal\PrivacyNoticeVersions;
use App\Legal\TermsVersions;
use Psr\Clock\ClockInterface;
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
    public function __construct(private readonly Security $security, private readonly ClockInterface $clock)
    {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cc_privacy_unseen', $this->unseen(...)),
            new TwigFunction('cc_terms_unseen', $this->termsUnseen(...)),
        ];
    }

    /** @return array{number: int, effective: string, upcoming: bool}|null */
    public function unseen(): ?array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->bar(PrivacyNoticeVersions::class, $user->getPrivacyVersionSeen()) : null;
    }

    /** @return array{number: int, effective: string, upcoming: bool}|null */
    public function termsUnseen(): ?array
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $this->bar(TermsVersions::class, $user->getTermsVersionSeen()) : null;
    }

    /**
     * The newest version this reader has not opened, and whether it is still
     * announced (applies later) or already applies.
     *
     * @param class-string<LegalVersions> $versions
     *
     * @return array{number: int, effective: string, upcoming: bool}|null
     */
    private function bar(string $versions, ?int $seen): ?array
    {
        $latest = $versions::latest();
        if (($seen ?? 0) >= $latest['number']) {
            return null;
        }

        return ['number' => $latest['number'], 'effective' => $latest['effective'], 'upcoming' => null !== $versions::upcoming($this->clock->now())];
    }
}
