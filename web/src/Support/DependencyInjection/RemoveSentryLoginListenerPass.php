<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support\DependencyInjection;

use Sentry\SentryBundle\EventListener\LoginListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Drops Sentry's LoginListener. It asks the token storage for a token on
 * every request, which makes the lazy firewall authenticate every request,
 * signed in or not, and its only purpose is to put the signed-in user's name
 * on each event: data we do not want in GlitchTip. The bundle offers no
 * switch for it.
 *
 * Registered in Kernel::build(), which Psalm does not scan.
 *
 * @api
 */
final class RemoveSentryLoginListenerPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        $container->removeDefinition(LoginListener::class);
    }
}
