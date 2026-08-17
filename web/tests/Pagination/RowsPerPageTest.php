<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Pagination;

use App\Account\RowsPerPage;
use App\Entity\User;
use App\Messaging\MessageService;
use App\Pagination\PageSize;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The rider preference that sets how long a page is, everywhere
 * (docs/specs/account-and-auth.md §9).
 */
final class RowsPerPageTest extends WebTestCase
{
    private function rider(string $email): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $u = (new User())->setEmail($email)->setDisplayName('Paging Rider');
        $u->setPassword('x');
        $u->setEmailVerified(true)->setEmailVerifiedAt(new \DateTimeImmutable());
        $em->persist($u);
        $em->flush();

        return $u;
    }

    /**
     * The page-length control's CSRF token, read off a rendered pager. Needs
     * a message to exist first: the pager is not furniture, so it does not
     * render over an empty list.
     */
    private function tokenFromAPager(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, int $userId): string
    {
        static::getContainer()->get(MessageService::class)->sendSystem(
            $userId,
            \App\Messaging\UserMessageKind::SubmissionApproved,
            'submission',
            1,
            'A place',
            'messages.body.submission_approved',
        );
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $crawler = $client->request('GET', '/messages');
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('.pager-size input[name="_token"]')->attr('value');
    }

    /**
     * `Auto` is not a number, and that is the whole design: each list keeps
     * the size it was built around, because a message is a card and a
     * contributors-wall row is one line.
     */
    public function testAutoLeavesEachListItsOwnDefault(): void
    {
        static::createClient();
        $size = static::getContainer()->get(PageSize::class);

        self::assertNull(RowsPerPage::Auto->rows());
        self::assertSame(20, $size->resolve(20));
        self::assertSame(60, $size->resolve(60));
    }

    /** A signed-out reader has nowhere to store a choice, so they get the default. */
    public function testASignedOutReaderGetsTheSurfaceDefault(): void
    {
        static::createClient();

        self::assertSame(25, static::getContainer()->get(PageSize::class)->resolve(25));
    }

    public function testAnExplicitChoiceOverridesEveryList(): void
    {
        $client = static::createClient();
        $rider = $this->rider('rows-explicit@example.test');
        $rider->setRowsPerPage(RowsPerPage::N100);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($rider, 'main');
        // A request so the firewall actually holds the token the resolver reads.
        $client->request('GET', '/messages');

        $size = static::getContainer()->get(PageSize::class);
        self::assertSame(100, $size->resolve(20), 'the messages default gives way');
        self::assertSame(100, $size->resolve(60), 'and so does the wall default');
    }

    /**
     * A stored value nobody recognises falls back rather than fatalling a
     * page. (The column is VARCHAR(8), so the garbage has to fit — which is
     * itself a small guard: no future option can be longer than 'auto'.).
     */
    public function testAnUnknownStoredValueReadsAsAuto(): void
    {
        static::createClient();
        $rider = $this->rider('rows-garbage@example.test');
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "UPDATE users SET rows_per_page = 'nope' WHERE id = :id",
            ['id' => (int) $rider->getId()],
        );
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $fresh = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $rider->getId());

        self::assertSame(RowsPerPage::Auto, $fresh?->getRowsPerPage());
    }

    /**
     * The pager's own control writes the same preference the settings tab
     * holds — one setting, two doors — and comes back to the list.
     */
    public function testThePagerControlSavesThePreferenceAndReturnsToTheList(): void
    {
        $client = static::createClient();
        $rider = $this->rider('rows-from-pager@example.test');
        $client->loginUser($rider, 'main');

        // Read the token off the pager the way the browser would — the
        // control only exists once there is a list to page.
        $token = $this->tokenFromAPager($client, (int) $rider->getId());

        $client->request('POST', '/settings/rows-per-page', [
            '_token' => $token, 'rows' => '50', 'back' => '/messages?cat=notices',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        self::assertResponseRedirects('/messages?cat=notices', null, 'it goes back to the list, filter and all');

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $fresh = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $rider->getId());
        self::assertSame(RowsPerPage::N50, $fresh?->getRowsPerPage());
    }

    /**
     * `back` is attacker-supplied, so an absolute URL must not turn a
     * logged-in POST into an open redirect off the site.
     */
    public function testAnAbsoluteBackUrlIsRefused(): void
    {
        $client = static::createClient();
        $rider = $this->rider('rows-open-redirect@example.test');
        $client->loginUser($rider, 'main');
        $token = $this->tokenFromAPager($client, (int) $rider->getId());

        // The control-character payloads are review 2026-08-16 finding 8:
        // browsers strip tab/CR/LF inside URLs before resolving, so a smuggled
        // "/\t//host" would leave the browser protocol-relative. The trailing
        // "\n" one pins the \A...\z anchoring ($ matches before a final newline).
        foreach (['https://evil.example/', '//evil.example/', 'javascript:alert(1)', "/\t//evil.example", "/\n//evil.example", "/messages\n.evil.example"] as $hostile) {
            $client->request('POST', '/settings/rows-per-page', [
                '_token' => $token, 'rows' => '25', 'back' => $hostile,
            ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

            $location = (string) $client->getResponse()->headers->get('Location');
            self::assertStringNotContainsString('evil.example', $location, $hostile);
            self::assertStringNotContainsString('javascript:', $location, $hostile);
        }
    }

    /** The messages list actually honours it, end to end. */
    public function testTheMessagesListHonoursTheChoice(): void
    {
        $client = static::createClient();
        $rider = $this->rider('rows-endtoend@example.test');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $messages = static::getContainer()->get(MessageService::class);
        for ($i = 0; $i < 30; ++$i) {
            $messages->sendSystem(
                (int) $rider->getId(),
                \App\Messaging\UserMessageKind::SubmissionApproved,
                'submission',
                $i + 1,
                'A place',
                'messages.body.submission_approved',
            );
        }
        $em->flush();
        $client->loginUser($rider, 'main');

        // The messages default is 20, so 30 messages are two pages…
        $crawler = $client->request('GET', '/messages');
        self::assertSame(20, $crawler->filter('.msg-row')->count());

        $rider->setRowsPerPage(RowsPerPage::N50);
        $em->flush();

        // …and one page at 50.
        $crawler = $client->request('GET', '/messages');
        self::assertSame(30, $crawler->filter('.msg-row')->count());
    }
}
