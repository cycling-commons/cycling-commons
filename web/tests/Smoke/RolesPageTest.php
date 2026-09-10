<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Smoke;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The contributors-and-curators page: the two roles on one page, with a link
 * to it from the curator application (docs/specs/moderation-and-contribution.md §8).
 */
final class RolesPageTest extends WebTestCase
{
    public function testRolesPageRenders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/contributors-and-curators');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('footer.foot');
        self::assertSelectorTextContains('h1', 'Contributors and curators');
        self::assertSelectorCount(2, '.half');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('/improve', $html, 'the contributor half links the wizard');
        self::assertStringContainsString('/regions', $html, 'the curator half links the regions page');
    }

    public function testRolesPageRendersInEveryLocale(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get('router');
        foreach (['en', 'fr', 'nl', 'de', 'es'] as $locale) {
            $client->request('GET', $router->generate('roles', ['_locale' => $locale]));
            self::assertResponseIsSuccessful(sprintf('the %s roles page must render', $locale));
        }
        self::assertSame('/nl/bijdragers-en-curatoren', $router->generate('roles', ['_locale' => 'nl']));
    }

    /** The application page is for signed-in riders, and only an onboarded country has one. */
    public function testCuratorApplicationLinksTheRolesPage(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('roles-test-nl', 'ROLES', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 1000, 'NL', 'NL', 2, 'test', NOW(), NOW())",
        );
        $u = new User();
        $u->setEmail('roles-page@example.test');
        $u->setDisplayName('Roles reader');
        $u->setPassword('x');
        $u->setEmailVerified(true);
        $em->persist($u);
        $em->flush();
        $client->loginUser($u, 'main');

        $client->request('GET', '/join/NL');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/contributors-and-curators"]');
    }
}
