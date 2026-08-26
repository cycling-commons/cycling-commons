<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Entity\User;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Unread bulb on the account chip (moderation-feedback spec M4, Task 6):
 * count rendering, the mark-all-read side effect clearing it,
 * and the anonymous no-DB-touch path.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class BulbTest extends WebTestCase
{
    private function createUser(string $email, string $plain, string $displayName = 'Test Rider'): string
    {
        $container = static::getContainer();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setDisplayName($displayName);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword($hasher->hashPassword($user, $plain));

        $em->persist($user);
        $em->flush();

        return $plain;
    }

    private function loginAs(KernelBrowser $client, string $email, string $plain): void
    {
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Sign in')->form([
            '_username' => $email,
            '_password' => $plain,
        ]);
        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    private function userId(string $email): int
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return (int) $user->getId();
    }

    private function svc(): MessageService
    {
        return static::getContainer()->get(MessageService::class);
    }

    public function testOneUnreadShowsBulbWithCount(): void
    {
        $client = static::createClient();

        $email = 'bulb-one@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Bulb One');
        $userId = $this->userId($email);

        $this->svc()->sendSystem(
            $userId,
            UserMessageKind::SubmissionApproved,
            'submission',
            1,
            'SUB-1 · Fountain',
            'messages.body.submission_approved',
            ['%title%' => 'Fountain'],
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.acct-bulb');
        self::assertSame('1', trim($crawler->filter('.acct-bulb')->text()));
    }

    public function testTwelveUnreadShowsTwelve(): void
    {
        $client = static::createClient();

        $email = 'bulb-many@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Bulb Many');
        $userId = $this->userId($email);

        $svc = $this->svc();
        for ($i = 1; $i <= 12; ++$i) {
            $svc->sendSystem(
                $userId,
                UserMessageKind::SubmissionApproved,
                'submission',
                $i,
                "SUB-{$i} · Fountain",
                'messages.body.submission_approved',
                ['%title%' => 'Fountain'],
            );
        }
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.acct-bulb');
        self::assertSame('12', trim($crawler->filter('.acct-bulb')->text()));
    }

    public function testVisitingMessagesClearsTheBulb(): void
    {
        $client = static::createClient();

        $email = 'bulb-clears@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Bulb Clears');
        $userId = $this->userId($email);

        $this->svc()->sendSystem(
            $userId,
            UserMessageKind::SubmissionApproved,
            'submission',
            1,
            'SUB-1 · Fountain',
            'messages.body.submission_approved',
            ['%title%' => 'Fountain'],
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.acct-bulb');

        $client->request('GET', '/messages');
        self::assertResponseIsSuccessful();

        $crawler2 = $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.acct-bulb');
        unset($crawler, $crawler2);
    }

    public function testLoggedOutPageRendersNoBulbAndNoError(): void
    {
        $client = static::createClient();

        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.acct-bulb');
    }
}
