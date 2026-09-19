<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Review 2026-08-16 finding 7: `access_control` backstops the controllers'
 * own IsGranted attributes, with a wildcard locale group `(/[a-z]{2})?` so a
 * future locale never silently falls outside the rules (owner, 2026-08-17).
 *
 * These assert the FENCE, not the controllers: anonymous requests bounce to
 * login even if someone later removed an attribute. /media/* and
 * /contribute/elevation are deliberately absent — THE stateless-JSON pattern
 * (security-architecture.md §5.1) owns their clean 401s, asserted in their
 * own controller tests.
 */
final class AccessControlBackstopTest extends WebTestCase
{
    public function testAnonymousMessagesBouncesToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/account/messages');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testAnonymousLocalizedMessagesBouncesToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/account/messages');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testAnonymousScoutTagsBouncesToLogin(): void
    {
        $client = static::createClient();
        $client->request('POST', '/scout/tags');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testWildcardDoesNotSwallowPublicPages(): void
    {
        $client = static::createClient();
        // The wildcard group must widen only the locale slot, never turn a
        // public page private: the localized login form itself stays reachable.
        $client->request('GET', '/fr/login');
        self::assertResponseIsSuccessful();
    }
}
