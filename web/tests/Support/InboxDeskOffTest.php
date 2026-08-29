<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Support\ContactTopic;
use App\Support\Entity\ContactMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The contact inbox desk is off (docs/specs/contact-and-support.md §8).
 *
 * Turned off on 2026-08-28 because it could show a message and not answer one:
 * a curator replied from their own mail client, the person's answer came back
 * to that mailbox, and this page never learned about either. Half a
 * conversation is worse than none, because a curator reading this page cannot
 * tell an answered message from an unanswered one.
 *
 * Two things this file holds down, and they pull in opposite directions:
 *
 * 1. **The desk is 404, for curators too.** Unlinked is not enough: a
 *    half-working page found by guessing the path is exactly the page that gets
 *    trusted.
 * 2. **Nothing is lost.** The contact form still works, still stores every
 *    message, and still mails it to the support address. Ordinary mail is the
 *    whole channel until the desk can hold a thread.
 */
final class InboxDeskOffTest extends WebTestCase
{
    private function client(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function curator(): User
    {
        $user = (new User())->setEmail('inbox-off@cyclingcommons.org');
        $user->setPassword('x');
        $user->setDisplayName('Inbox Curator');
        $user->setRoles(['ROLE_CURATOR']);
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    public function testTheDeskIsNotFoundEvenForACurator(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        foreach (['/moderate/inbox', '/moderate/inbox/1', '/fr/moderate/inbox'] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testTheHandleEndpointIsNotFoundEither(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $client->request('POST', '/moderate/inbox/handle', ['id' => 1, 'status' => 'closed']);

        self::assertResponseStatusCodeSame(404);
    }

    /** Nothing links to it, so nobody can arrive there by clicking. */
    public function testTheModerationTabsDoNotOfferIt(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $page->filter('a[href$="/moderate/inbox"]'));
    }

    /**
     * The messages themselves are untouched.
     *
     * Turning the constant back to true has to show the full history, so the
     * rows must keep arriving while the desk is dark.
     */
    public function testContactMessagesAreStillStored(): void
    {
        self::bootKernel();

        $before = \count($this->em()->getRepository(ContactMessage::class)->findAll());

        $message = new ContactMessage(
            ContactTopic::Question,
            'someone@cyclingcommons.org',
            'A question while the desk is off',
        );
        $this->em()->persist($message);
        $this->em()->flush();

        self::assertCount($before + 1, $this->em()->getRepository(ContactMessage::class)->findAll());
    }
}
