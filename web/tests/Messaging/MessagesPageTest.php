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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `/account/messages` account-shell page (moderation-feedback spec M3): row display,
 * unread styling, no read side effect on a visit, per-user isolation,
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
        $client->request('GET', '/account/messages');

        self::assertResponseRedirects('/login?_target_path=%2Faccount%2Fmessages', 302);
    }

    // ── Rows + unread styling, and a visit marks nothing ───────────────────

    public function testRiderSeesUnreadRowsAndTheyStayUnreadUntilOpened(): void
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

        $crawler = $client->request('GET', '/account/messages');
        self::assertResponseIsSuccessful();

        // Both rows are present, translated headline included, and marked new.
        self::assertCount(2, $crawler->filter('.msg-row'));
        self::assertCount(2, $crawler->filter('.msg-row.msg-new'));
        $listText = $crawler->filter('.msg-list')->text();
        self::assertStringContainsString('Contribution approved — thank you!', $listText);
        self::assertStringContainsString('Please clarify the gate location.', $listText);
        self::assertStringContainsString('From a curator', $listText);

        // Visiting marks nothing: a message is read once it is opened (§7.5a).
        self::assertSame(2, $svc->unreadCount($riderId));

        // A second visit shows the same rows, still flagged as new.
        $crawler2 = $client->request('GET', '/account/messages');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler2->filter('.msg-row'));
        self::assertCount(2, $crawler2->filter('.msg-row.msg-new'));
    }

    /**
     * The name in the body line is italic between single quotes, and it is
     * user text: a name that looks like markup is shown as text.
     */
    public function testTheBodyLineShowsTheNameItalicAndEscaped(): void
    {
        $client = static::createClient();

        $email = 'messages-italic@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Italic Rider');
        $riderId = $this->userId($email);

        $svc = $this->svc();
        $svc->sendSystem($riderId, UserMessageKind::SubmissionApproved, 'submission', 111, 'SUB-111',
            'messages.body.submission_approved', ['%title%' => 'Zuder weg']);
        $svc->sendSystem($riderId, UserMessageKind::SubmissionRejected, 'submission', 112, 'SUB-112',
            'messages.body.submission_rejected', ['%title%' => '<script>alert(1)</script><b>x</b>']);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->loginAs($client, $email, $plain);
        $crawler = $client->request('GET', '/account/messages');
        self::assertResponseIsSuccessful();

        $approved = $crawler->filter('.msg-row')->reduce(
            static fn (Crawler $row): bool => str_contains($row->text(), 'SUB-111'),
        );
        self::assertCount(1, $approved);
        $body = $approved->filter('.msg-body');
        self::assertStringContainsString("'<em>Zuder weg</em>'", html_entity_decode($body->html(), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        self::assertStringContainsString("Your contribution 'Zuder weg' was approved", $body->text());

        $hostile = $crawler->filter('.msg-row')->reduce(
            static fn (Crawler $row): bool => str_contains($row->text(), 'SUB-112'),
        );
        self::assertCount(1, $hostile);
        $hostileBody = $hostile->filter('.msg-body');
        self::assertCount(0, $hostileBody->filter('script'), 'no script element from a name');
        self::assertCount(0, $hostileBody->filter('b'), 'no bold element from a name');
        self::assertSame('<script>alert(1)</script><b>x</b>', $hostileBody->filter('em')->text());
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&lt;b&gt;x&lt;/b&gt;', (string) $client->getResponse()->getContent());
    }

    public function testEmptyStateShownWhenNoMessages(): void
    {
        $client = static::createClient();

        $email = 'messages-empty@example.com';
        $plain = $this->createUser($email, 'securepass12345!', 'Empty Rider');
        $this->loginAs($client, $email, $plain);

        $crawler = $client->request('GET', '/account/messages');
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

        $crawler = $client->request('GET', '/account/messages');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.msg-row'));
        self::assertSelectorTextNotContains('.dbody', 'This belongs to owner only.');
    }
}
