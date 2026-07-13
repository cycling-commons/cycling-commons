<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RouteModerateShellTest extends WebTestCase
{
    public function testCuratorShellShowsARoutesTab(): void
    {
        $client = static::createClient();
        $c = static::getContainer();
        $u = (new User())->setEmail('curator2@test.test')->setDisplayName('C');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles(['ROLE_CURATOR'])->setTotpSecret('JBSWY3DPEHPK3PXP')->setTwoFaEnabled(true);
        $u->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        $c->get(EntityManagerInterface::class)->persist($u);
        $c->get(EntityManagerInterface::class)->flush();

        $client->loginUser($u);
        $client->request('GET', '/moderate/routes');
        self::assertResponseIsSuccessful();
        // The shared shell exposes a Routes tab pointing at this desk.
        self::assertSelectorExists('a[href$="/moderate/routes"].dtab-mod, nav.dtabs a[href$="/moderate/routes"]');
    }
}
