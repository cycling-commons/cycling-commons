<?php

// SPDX-License-Identifier: AGPL-3.0-only

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
 * `/messages` account-shell page (moderation-feedback spec M3): row display,
 * unread styling, the mark-all-read-on-visit side effect, per-user isolation,
 * and the anon auth gate.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back transaction.
 */
final class MessagesPageTest extends WebTestCase
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

    // ── Anon redirect ───────────────────────────────────────────────────────

    public function testAnonIsRedirectedFromMessages(): void
    {
        $client = static::createClient();
        $client->request('GET', '/messages');

        self::assertResponseRedirects('/login', 302);
    }

    // ── Rows + unread styling + mark-all-read side effect ──────────────────

    public function testRiderSeesUnreadRowsThenTheyAreMarkedRead(): void
    {
        $client = static::createClient();

        $email = 'messages-rider@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Msg Rider');
        $riderId = $this->userId($email);
        $curatorEmail = 'messages-curator@example.com';
        $this->createUser($curatorEmail, 'securepass12345!', 'Msg Curator');
        $curatorId = $this->userId($curatorEmail);

        $svc = $this->svc();
        $svc->sendSystem(
            $riderId,
            UserMessageKind::SubmissionApproved,
            'submission',
            101,
            'SUB-101 · Fountain',
            'messages.body.submission_approved',
            ['%title%' => 'Fountain'],
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $svc->sendCurator($riderId, $curatorId, 'correction', 202, 'Côte de Stockeu', 'Please clarify the gate location.');

        self::assertSame(2, $svc->unreadCount($riderId));

        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/messages');
        self::assertResponseIsSuccessful();

        // Both rows are present, translated headline included, and marked new.
        self::assertCount(2, $crawler->filter('.msg-row'));
        self::assertCount(2, $crawler->filter('.msg-row.msg-new'));
        $listText = $crawler->filter('.msg-list')->text();
        self::assertStringContainsString('Contribution approved — thank you!', $listText);
        self::assertStringContainsString('Please clarify the gate location.', $listText);
        self::assertStringContainsString('From a curator', $listText);

        // Visiting marks everything read.
        self::assertSame(0, $svc->unreadCount($riderId));

        // A second visit shows the same rows, but none flagged as new.
        $crawler2 = $client->request('GET', '/messages');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler2->filter('.msg-row'));
        self::assertCount(0, $crawler2->filter('.msg-row.msg-new'));
    }

    public function testEmptyStateShownWhenNoMessages(): void
    {
        $client = static::createClient();

        $email = 'messages-empty@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Empty Rider');
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/messages');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.msg-row'));
        self::assertSelectorExists('.empty-state');
    }

    public function testOtherUsersMessagesAreNotShown(): void
    {
        $client = static::createClient();

        $ownerEmail = 'messages-owner@example.com';
        $this->createUser($ownerEmail, 'securepass12345!', 'Owner Rider');
        $ownerId = $this->userId($ownerEmail);
        $curatorEmail = 'messages-owner-curator@example.com';
        $this->createUser($curatorEmail, 'securepass12345!', 'Owner Curator');
        $curatorId = $this->userId($curatorEmail);

        $this->svc()->sendCurator($ownerId, $curatorId, 'correction', 303, 'Secret Ref', 'This belongs to owner only.');

        $viewerEmail = 'messages-viewer@example.com';
        $viewerPlain = $this->createUser($viewerEmail, 'securepass12345!', 'Viewer Rider');
        $this->loginAs($client, $viewerEmail, $viewerPlain);

        $crawler = $client->request('GET', '/messages');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.msg-row'));
        self::assertSelectorTextNotContains('.dbody', 'This belongs to owner only.');
    }
}
