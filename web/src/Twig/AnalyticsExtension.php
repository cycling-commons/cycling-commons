<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `analytics_on_page()`: whether this page carries the visitor-analytics tag.
 *
 * Public pages only (owner 2026-09-30: "never backend or user/contributor
 * parts"). A signed-in area (account, moderation, admin, translate mode) is
 * nobody's traffic to count, and four paths carry a one-time token in their
 * address (confirm, reset, unsubscribe, the 2FA step), which must never reach
 * a third system. Everything else, including sign-in and sign-up, is a public
 * page and is counted. Decided on the path alone, so a cached public page and
 * a fresh one print the same tag.
 *
 * @see docs/specs/security-architecture.md §2
 *
 * @api
 */
final class AnalyticsExtension extends AbstractExtension
{
    /** First path segment, after an optional locale prefix, of every page that is not counted. */
    public const array PRIVATE_SEGMENTS = [
        'account', 'admin', 'moderate', 'curator', 'translate',
        '2fa', '2fa_check', 'verify', 'reset-password', 'unsubscribe',
        // Former account addresses, which redirect into /account.
        'messages', 'profile', 'settings',
    ];

    public function __construct(
        private readonly RequestStack $requests,
        #[Autowire('%env(bool:CC_ANALYTICS)%')]
        private readonly bool $enabled = false,
    ) {
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [new TwigFunction('analytics_on_page', $this->onPage(...))];
    }

    public function onPage(): bool
    {
        $request = $this->requests->getMainRequest();

        return $this->enabled && null !== $request && self::isPublicPath($request->getPathInfo());
    }

    public static function isPublicPath(string $path): bool
    {
        $segment = explode('/', (string) preg_replace('~^/[a-z]{2}(?=/|$)~', '', $path), 3)[1] ?? '';

        return !\in_array($segment, self::PRIVATE_SEGMENTS, true);
    }
}
