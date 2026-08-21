<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Request locale: routed `_locale`, then session, then Accept-Language, then default.
 *
 * @api
 */
final class LocaleSubscriber implements EventSubscriberInterface
{
    /** @param list<string> $enabledLocales */
    public function __construct(
        #[Autowire('%kernel.default_locale%')] private readonly string $defaultLocale,
        #[Autowire('%kernel.enabled_locales%')] private readonly array $enabledLocales,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        $routed = $request->attributes->get('_locale');
        if (\is_string($routed) && \in_array($routed, $this->enabledLocales, true)) {
            if ($request->hasSession()) {
                $request->getSession()->set('_locale', $routed);
            }

            return;
        }

        if ($request->hasPreviousSession()) {
            $stored = $request->getSession()->get('_locale');
            if (\is_string($stored) && \in_array($stored, $this->enabledLocales, true)) {
                $request->setLocale($stored);

                return;
            }
        }

        $request->setLocale($request->getPreferredLanguage($this->enabledLocales) ?: $this->defaultLocale);
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => [['onKernelRequest', 20]]];
    }
}
