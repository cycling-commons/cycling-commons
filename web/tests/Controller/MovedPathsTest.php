<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The rider pages live under /account and the submissions queue under
 * /moderate/submissions (account-and-auth.md §8). A link to an older path, in
 * a sent email or a bookmark, still lands, with its query string.
 */
final class MovedPathsTest extends WebTestCase
{
    public function testRiderPathsRedirectPermanentlyUnderAccount(): void
    {
        $client = static::createClient();
        $this->login($client, 'moved-rider@example.com', []);

        foreach ([
            '/profile' => '/account/contributions',
            '/profile?letter=B' => '/account/contributions?letter=B',
            '/profile/reports' => '/account/reports',
            '/messages' => '/account/messages',
            '/settings' => '/account/settings',
            '/fr/messages' => '/fr/account/messages',
        ] as $old => $new) {
            $client->request('GET', $old);
            self::assertResponseRedirects($new, 301, $old);
        }
    }

    public function testAFilteredModerateLinkGoesOnToTheQueue(): void
    {
        $client = static::createClient();
        $this->login($client, 'moved-curator@example.com', ['ROLE_CURATOR']);

        $client->request('GET', '/moderate?q=SUB-12');
        self::assertResponseRedirects('/moderate/submissions?q=SUB-12', 301);

        $client->request('GET', '/moderate/history');
        self::assertResponseRedirects('/moderate/submissions/history', 301);

        // Without a query, /moderate is the dashboard itself.
        $client->request('GET', '/moderate');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.db-tile[href$="/moderate/submissions"]');
    }

    /**
     * @param list<string> $roles
     */
    private function login(KernelBrowser $client, string $email, array $roles): void
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Moved pen');
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
