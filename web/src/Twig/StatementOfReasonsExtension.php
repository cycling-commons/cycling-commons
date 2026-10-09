<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Moderation\StatementOfReasons;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The statement of reasons a message carries, in the reader's language, for
 * the messages page. The same lines the email has
 * ({@see StatementOfReasons::lines()}), so the two cannot drift.
 *
 * @see docs/specs/content-reports.md §7
 *
 * @api
 */
final class StatementOfReasonsExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requests,
        private readonly Security $security,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('statement_of_reasons', $this->lines(...)),
        ];
    }

    /**
     * @param array<string, mixed>|null $bodyParams a message's body params
     *
     * @return array<string, string|null>|null
     */
    public function lines(?array $bodyParams): ?array
    {
        $statement = StatementOfReasons::fromArray($bodyParams[StatementOfReasons::PARAM] ?? null);
        if (null === $statement) {
            return null;
        }
        $locale = $this->requests->getCurrentRequest()?->getLocale() ?? 'en';
        $user = $this->security->getUser();
        $zone = $user instanceof User ? ($user->getTimeZone() ?? $user->getDetectedTimeZone()) : null;

        return $statement->lines($this->translator, $locale, false, $zone ?? 'Europe/Amsterdam');
    }
}
