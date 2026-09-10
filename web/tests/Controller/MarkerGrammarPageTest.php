<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The curator marker page draws every rung of the ladder with the real pin
 * classes (data-provider-hierarchy.md §6.7.7), so the grammar can be checked
 * by eye. Curator-only: it is a QA surface, and /map-key stays the rider
 * legend.
 */
final class MarkerGrammarPageTest extends WebTestCase
{
    public function testTheGrammarPageIsCuratorOnly(): void
    {
        $client = static::createClient();
        $client->request('GET', '/curator/markers');

        self::assertResponseRedirects();
    }

    public function testARiderIsRefused(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->login($client, 'markers-rider@example.test', []);

        $client->request('GET', '/curator/markers');

        self::assertResponseStatusCodeSame(403);
    }

    public function testItRendersEveryRungOnceWithTheRealPinClasses(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->login($client, 'markers-curator@example.test', ['ROLE_CURATOR']);

        $crawler = $client->request('GET', '/curator/markers');

        self::assertResponseIsSuccessful();
        self::assertCount(11, $crawler->filter('tr[data-rung]'), 'one row per rung, 1 to 11');
        self::assertCount(7, $crawler->filter('tr[data-badge="?"]'), 'rungs 1 to 6 and 8 keep the ?');
        self::assertCount(4, $crawler->filter('tr[data-badge=""]'), 'rungs 7, 9, 10 and 11 drop it');
        // Each row draws the pin twice, on the dark and on the light ground.
        self::assertCount(2, $crawler->filter('tr[data-rung="4"] .cc-pin.dashed.q'), 'rung 4: a specialty provider, nobody stood there');
        self::assertCount(2, $crawler->filter('tr[data-rung="1"] .cc-pin.disc.q'), 'rung 1: a gross provider, nobody stood there');
        self::assertCount(2, $crawler->filter('tr[data-rung="9"] .cc-pin:not(.q):not(.dashed):not(.disc)'), 'rung 9: ours, verified, no mark at all');
        self::assertSame('minimum', $crawler->filter('tr[data-rung="9"]')->attr('data-grade'));
        self::assertSame('high', $crawler->filter('tr[data-rung="11"]')->attr('data-grade'));
        // The grid: three borders by two badge states, six cells.
        self::assertCount(6, $crawler->filter('[data-grid]'));
        self::assertCount(1, $crawler->filter('[data-grid="specialty:q"] .cc-pin.dashed.q'));
        self::assertCount(1, $crawler->filter('[data-grid="ours:"] .cc-pin:not(.q)'));
        // The pin rules come from the one shared stylesheet, never a copy.
        self::assertStringContainsString('styles/pins', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('paper dot', strtolower((string) $client->getResponse()->getContent()));
    }

    /** @param list<string> $roles */
    private function login(KernelBrowser $client, string $email, array $roles): void
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Marker eye');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles($roles);
        if ([] !== $roles) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $user->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($user, 'securepass12345!'));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);
    }
}
