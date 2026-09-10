<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\MediaConsent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Consent is stored BEFORE any upload is possible, and it is fail-closed at
 * every step (docs/specs/photo-uploads.md §4): the endpoint answers "no
 * consent" until a matching record exists, an anonymous caller gets a clean 401
 * rather than a login redirect, and a forged token gets nothing.
 *
 * Test isolation: DAMA wraps each test in a rolled-back transaction.
 */
final class ConsentEndpointTest extends WebTestCase
{
    private function login(KernelBrowser $client, string $tag): User
    {
        $container = static::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $em = $container->get(EntityManagerInterface::class);

        $email = "consent-{$tag}@example.com";
        $plain = 'securepass12345!';
        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName('Consent Rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, $plain));
        $em->persist($user);
        $em->flush();

        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Sign in')->form(['_username' => $email, '_password' => $plain]);
        $client->submit($form);
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        return $user;
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        $decoded = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function token(KernelBrowser $client): string
    {
        $client->request('GET', '/media/token');
        self::assertResponseIsSuccessful();

        return (string) $this->json($client)['token'];
    }

    public function testAnonymousCallersGetACleanUnauthorized(): void
    {
        $client = static::createClient();

        $client->request('GET', '/media/consent/current');
        self::assertResponseStatusCodeSame(401, 'a JSON API answers 401, it never redirects to a login page');

        $client->request('POST', '/media/consent');
        self::assertResponseStatusCodeSame(401);

        $client->request('GET', '/media/token');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCurrentConsentStartsNegative(): void
    {
        $client = static::createClient();
        $this->login($client, 'fresh');

        $client->request('GET', '/media/consent/current');
        self::assertResponseIsSuccessful();

        $data = $this->json($client);
        self::assertNull($data['consentId'], 'consent is negative until a record proves otherwise');
        self::assertSame(MediaConsent::VERSION, $data['version']);
        self::assertNotSame('', $data['contract'], 'the contract text travels with the answer');
    }

    public function testRecordingConsentStoresALedgerRowAndIsThenReported(): void
    {
        $client = static::createClient();
        $user = $this->login($client, 'record');

        $client->request('POST', '/media/consent', ['_token' => $this->token($client)]);
        self::assertResponseIsSuccessful();
        $posted = $this->json($client);
        self::assertNotEmpty($posted['consentId']);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $records = $em->getRepository(ConsentRecord::class)->findBy(['userId' => (int) $user->getId()]);
        self::assertCount(1, $records);
        self::assertSame(MediaConsent::KIND, $records[0]->getKind());
        self::assertSame(MediaConsent::VERSION, $records[0]->getVersion());
        self::assertSame(64, \strlen($records[0]->getTextHash()));

        // The cross-visit bootstrap: a later visit is told it already consented.
        $client->request('GET', '/media/consent/current');
        self::assertResponseIsSuccessful();
        $current = $this->json($client);
        self::assertSame($posted['consentId'], $current['consentId']);
        self::assertNotNull($current['consentedAt']);
    }

    public function testConsentIsAppendOnlyNotAnUpsert(): void
    {
        $client = static::createClient();
        $user = $this->login($client, 'append');
        $token = $this->token($client);

        $client->request('POST', '/media/consent', ['_token' => $token]);
        self::assertResponseIsSuccessful();
        $client->request('POST', '/media/consent', ['_token' => $token]);
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(
            2,
            $em->getRepository(ConsentRecord::class)->findBy(['userId' => (int) $user->getId()]),
            'each consent act is its own immutable row',
        );
    }

    public function testAForgedTokenRecordsNothing(): void
    {
        $client = static::createClient();
        $user = $this->login($client, 'forged');

        $client->request('POST', '/media/consent', ['_token' => 'not-a-real-token']);
        self::assertResponseStatusCodeSame(403);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertCount(0, $em->getRepository(ConsentRecord::class)->findBy(['userId' => (int) $user->getId()]));
    }

    public function testARecordAtAnOtherVersionDoesNotCount(): void
    {
        $client = static::createClient();
        $user = $this->login($client, 'oldversion');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new ConsentRecord(
            Uuid::v4(), (int) $user->getId(),
            MediaConsent::KIND, 'v0', MediaConsent::hash('older wording'),
        ));
        $em->flush();

        $client->request('GET', '/media/consent/current');
        self::assertResponseIsSuccessful();
        self::assertNull(
            $this->json($client)['consentId'],
            'a wording-version change means a new consent act, never a silent carry-over',
        );
    }

    public function testAnotherRidersConsentIsNotYours(): void
    {
        $client = static::createClient();
        $mine = $this->login($client, 'mine');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $stranger = new ConsentRecord(
            Uuid::v4(), (int) $mine->getId() + 10_000,
            MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('same words'),
        );
        $em->persist($stranger);
        $em->flush();

        $client->request('GET', '/media/consent/current');
        self::assertResponseIsSuccessful();
        self::assertNull(
            $this->json($client)['consentId'],
            'consent is per rider — somebody else granting the licence proves nothing about this caller',
        );
    }
}
