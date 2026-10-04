<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaEscalationService;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A held photo is out of the public bucket (docs/specs/photo-uploads.md §6d),
 * so the admin's legal-hold page reads it through its own route.
 */
final class EscalatedPhotoTest extends WebTestCase
{
    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $u = (new User())->setEmail($email);
        $u->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setPassword('x');
        $u->setRoles($roles);
        if (\in_array('ROLE_ADMIN', $roles, true)) {
            // Fully enrolled, else the 2FA enforcer sends the request to /2fa/setup.
            $u->setTotpSecret('JBSWY3DPEHPK3PXP');
            $u->setTwoFaEnabled(true);
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function heldPhoto(): MediaUpload
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user('held-owner@example.com');
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 900, 600, 4242, bucket: 'test-bucket-eu-01');
        $em->persist($upload);
        $em->flush();
        static::getContainer()->get(MediaStorage::class)
            ->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 900, 600));

        static::getContainer()->get(MediaEscalationService::class)
            ->escalate($upload, $this->user('held-curator@example.com'), 'Escalating.');

        return $upload;
    }

    public function testAnAdminSeesTheHeldPhotoPrivately(): void
    {
        $client = static::createClient();
        $upload = $this->heldPhoto();
        $client->loginUser($this->user('held-admin@example.com', ['ROLE_ADMIN']));

        $client->request('GET', '/admin/escalated/photo/'.$upload->getId()->toRfc4122());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/webp');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('S', $client->getResponse()->getContent());
    }

    public function testACuratorCannotReachIt(): void
    {
        $client = static::createClient();
        $upload = $this->heldPhoto();
        $client->loginUser($this->user('held-curator-2@example.com', ['ROLE_CURATOR']));

        $client->request('GET', '/admin/escalated/photo/'.$upload->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(403);
    }

    public function testAPhotoThatIsNotHeldIsNotFound(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user('held-admin-2@example.com', ['ROLE_ADMIN']));

        $client->request('GET', '/admin/escalated/photo/'.Uuid::v4()->toRfc4122());

        self::assertResponseStatusCodeSame(404);
    }
}
