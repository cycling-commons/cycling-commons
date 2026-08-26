<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The wizard's photo step is real and photos-only
 * (docs/specs/photo-uploads.md §1.1, §4): a genuine file input, a hidden
 * mediaIds field that carries the uploads to intake, and no video anything —
 * neither the drop zone nor the link row.
 */
final class WizardMediaTest extends WebTestCase
{
    private function login(KernelBrowser $client, string $tag): void
    {
        $container = static::getContainer();
        $email = "wizmedia-{$tag}@example.com";
        $plain = 'securepass12345!';

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Wizard Rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, $plain));
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Sign in')->form(['_username' => $email, '_password' => $plain]));
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testThePhotoStepIsRealAndCarriesMediaIds(): void
    {
        $client = static::createClient();
        $this->login($client, 'fields');

        $client->request('GET', '/improve?type=water-food&mode=add');
        self::assertResponseIsSuccessful();

        self::assertSelectorExists('input[name="improve[mediaIds]"]');
        self::assertSelectorExists('input#file-photo[type="file"][multiple]');
        self::assertSelectorExists('#media-consent');
        self::assertSelectorExists('#media-consent-review');
    }

    public function testEveryTraceOfVideoIsGone(): void
    {
        $client = static::createClient();
        $this->login($client, 'novideo');

        $client->request('GET', '/improve?type=water-food&mode=add');
        self::assertResponseIsSuccessful();

        self::assertSelectorNotExists('input[name="improve[videoUrl]"]');
        self::assertSelectorNotExists('#drop-video');
        self::assertSelectorNotExists('#lnk-video');
        self::assertSelectorNotExists('#btn-link-video');

        $client->request('GET', '/improve?type=climbs&mode=add');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#drop-video');
    }

    public function testAddingAClimbCarriesTheSameRealUploader(): void
    {
        $client = static::createClient();
        $this->login($client, 'climb');

        // The old /add-climb wizard (a decorative drop zone, then a pointer to
        // /improve) was retired on 2026-08-25; climbs are added on /improve like
        // every other type, so the uploader is the one real one.
        $client->request('GET', '/improve?type=climbs&mode=add');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.drop[onclick]', 'a zone that refuses its own click is decoration, not a control');
        self::assertSelectorExists('input[name="improve[mediaIds]"]');
        self::assertSelectorExists('#drop-photo');
        self::assertSelectorExists('#media-consent');
    }

    public function testTheFileInputAcceptsExactlyTheSupportedFormats(): void
    {
        $client = static::createClient();
        $this->login($client, 'accept');

        $crawler = $client->request('GET', '/improve?type=water-food&mode=add');
        self::assertResponseIsSuccessful();

        $accept = $crawler->filter('input#file-photo')->attr('accept');
        self::assertNotNull($accept);
        foreach (['image/jpeg', 'image/png', 'image/webp', 'image/heic'] as $mime) {
            self::assertStringContainsString($mime, $accept);
        }
    }
}
