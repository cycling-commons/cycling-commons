<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The curator view of a withheld photo is for curators, and says nothing about
 * a photo that is not withheld (audit 2026-10-04).
 *
 * @see docs/specs/photo-uploads.md §6b, §6c
 */
final class WithheldPhotoViewTest extends WebTestCase
{
    public function testARiderCannotOpenItAndAnUnknownPhotoIsNotFound(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $rider = (new User())->setEmail('withheld-view-rider@example.test')->setDisplayName('Rider');
        $rider->setPassword('x');
        $curator = (new User())->setEmail('withheld-view-curator@example.test')->setDisplayName('Curator');
        $curator->setPassword('x');
        $curator->setRoles(['ROLE_CURATOR']);
        $curator->setTotpSecret('JBSWY3DPEHPK3PXP');
        $curator->setTwoFaEnabled(true);
        $em->persist($rider);
        $em->persist($curator);
        $em->flush();
        $path = '/moderate/withheld-photo/'.Uuid::v4()->toRfc4122();

        $client->loginUser($rider);
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(403);

        $client->loginUser($curator);
        $client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
    }
}
