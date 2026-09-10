<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Support\BugStatus;
use App\Support\Entity\BugReport;
use App\Support\SupportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The visible reference, and finding a report by it
 * (docs/specs/contact-and-support.md §9).
 *
 * Two things a desk needs that this one did not have: the number a reporter
 * quotes back at us, on the row rather than only inside the report, and a way
 * to get from that number to the report without paging through the archive.
 *
 * The property that matters most here is the last one: **a search clears the
 * "New" default.** Somebody typing a reference is chasing a report a reporter
 * has replied about, which by then is rarely still New. A search that silently
 * kept the default filter would return nothing and look broken.
 */
final class BugDeskSearchTest extends WebTestCase
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
        $user = (new User())->setEmail('bug-search@cyclingcommons.org');
        $user->setPassword('x');
        $user->setDisplayName('Search Desk Curator');
        $user->setRoles(['ROLE_CURATOR']);
        // Elevated roles must be TOTP-enrolled or every page redirects to
        // /2fa/setup (docs/specs/account-and-auth.md).
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $user->setTwoFaEnabled(true);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function bug(string $title, string $body, ?string $email = null, ?BugStatus $status = null): BugReport
    {
        $report = new BugReport($title, $body);
        if (null !== $email) {
            $report->setReporterEmail($email);
        }
        if (null !== $status) {
            $report->setStatus($status);
        }
        $this->em()->persist($report);
        $this->em()->flush();

        return $report;
    }

    // -- the reference ---------------------------------------------------

    public function testAReferenceIsAHashAndTheId(): void
    {
        self::bootKernel();
        $report = $this->bug('The map will not load', 'It stayed grey.');

        self::assertSame('#'.$report->getId(), $report->getReference());
        self::assertMatchesRegularExpression('/^#[1-9]\d*$/', $report->getReference());
    }

    public function testTheReferenceIsOnTheRowNotOnlyInsideTheReport(): void
    {
        $client = $this->client();
        $report = $this->bug('The map will not load', 'It stayed grey.');
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($report->getReference(), $page->text());
    }

    // -- parsing what somebody pastes -------------------------------------

    /**
     * People paste the hash, the number alone, and a padded one. All the same
     * lookup.
     */
    public function testEveryShapeOfAReferenceParsesToTheSameId(): void
    {
        foreach (['#123', '123', '000123', '  #123  '] as $typed) {
            self::assertSame(123, SupportRepository::bugIdFromReference($typed), $typed);
        }
    }

    /**
     * The old shape has to keep working.
     *
     * References were `CC-B-000123` until 2026-08-28 and that string is sitting
     * in mail people already received. Somebody quoting it in a reply years
     * from now must still be findable.
     */
    public function testTheOldReferenceShapeStillFindsTheReport(): void
    {
        foreach (['CC-B-000123', 'cc-b-000123', 'CC-B-123'] as $typed) {
            self::assertSame(123, SupportRepository::bugIdFromReference($typed), $typed);
        }
    }

    public function testProseIsNotAReference(): void
    {
        foreach (['grey map', 'CC-M-000123', 'rider@example.test', '', '12x'] as $typed) {
            self::assertNull(SupportRepository::bugIdFromReference($typed), $typed);
        }
    }

    // -- searching --------------------------------------------------------

    /**
     * A number OPENS that report. It does not filter the list down to it.
     *
     * The hint under the box has always said so; for a day the page did the
     * other thing (owner, 2026-08-28). Jumping is what somebody pasting a
     * number out of a reporter's reply is actually after.
     */
    public function testAReferenceOpensThatOneReport(): void
    {
        $client = $this->client();
        $wanted = $this->bug('The map will not load', 'It stayed grey.');
        $this->bug('Something else entirely', 'Also about a map.');
        $client->loginUser($this->curator());

        // urlencode, because `#` starts a fragment. This is what the GET form
        // sends; a curator hand-typing the query string would lose it.
        $client->request('GET', '/moderate/bugs?q='.urlencode($wanted->getReference()));

        self::assertResponseRedirects('/moderate/bugs/'.$wanted->getId());

        $page = $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The map will not load', $page->text());
        self::assertStringNotContainsString('Something else entirely', $page->text());
    }

    /** A bare number works the same, and so does the old prefixed shape. */
    public function testABareNumberAndTheOldShapeBothOpenIt(): void
    {
        $client = $this->client();
        $wanted = $this->bug('The map will not load', 'It stayed grey.');
        $client->loginUser($this->curator());

        foreach ([(string) $wanted->getId(), \sprintf('CC-B-%06d', $wanted->getId())] as $typed) {
            $client->request('GET', '/moderate/bugs?q='.urlencode($typed));
            self::assertResponseRedirects('/moderate/bugs/'.$wanted->getId(), null, $typed);
        }
    }

    /**
     * A number nobody has used falls through to the search.
     *
     * Redirecting to a 404 would be a worse answer than "nothing matches".
     */
    public function testANumberWithNoReportBehindItJustSearches(): void
    {
        $client = $this->client();
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs?q=%23999999');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('999999', $page->text());
    }

    /**
     * The one that makes the feature worth having.
     *
     * A resolved report is not New, so without clearing the default this search
     * would find nothing and the curator would conclude the report was deleted.
     */
    public function testASearchFindsAReportThatIsNoLongerNew(): void
    {
        $client = $this->client();
        $old = $this->bug('A gate that is long since fixed', 'Body.', null, BugStatus::Resolved);
        $client->loginUser($this->curator());

        $client->request('GET', '/moderate/bugs?q='.urlencode($old->getReference()));

        // It opens rather than filtering, which is the point: the "New" default
        // could never have shown it.
        self::assertResponseRedirects('/moderate/bugs/'.$old->getId());
        self::assertStringContainsString('A gate that is long since fixed', $client->followRedirect()->text());
    }

    public function testSearchingTheBody(): void
    {
        $client = $this->client();
        $this->bug('Grey tiles', 'The elevation profile is upside down.', 'jan@example.test');
        $this->bug('Unrelated', 'Nothing to do with it.', 'other@example.test');
        $client->loginUser($this->curator());

        $byBody = $client->request('GET', '/moderate/bugs?q=upside+down')->text();
        self::assertStringContainsString('Grey tiles', $byBody);
        self::assertStringNotContainsString('Unrelated', $byBody);
    }

    /**
     * A curator cannot search by address either.
     *
     * Searching one you already have reveals no new address, but a hit
     * confirms that a named person filed a report, which is the same
     * disclosure by another route (owner, 2026-08-28).
     */
    public function testACuratorCannotFindAReportByTheReportersAddress(): void
    {
        $client = $this->client();
        $this->bug('Grey tiles', 'Body.', 'jan@example.test');
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs?q=jan@example.test')->text();

        self::assertStringNotContainsString('Grey tiles', $page);
    }

    /** And the address is never rendered on the desk at all. */
    public function testTheAddressIsNeverShownOnTheDesk(): void
    {
        $client = $this->client();
        $report = $this->bug('Grey tiles', 'Body.', 'jan@example.test');
        $client->loginUser($this->curator());

        $list = $client->request('GET', '/moderate/bugs')->text();
        self::assertStringNotContainsString('jan@example.test', $list);

        $detail = $client->request('GET', '/moderate/bugs/'.$report->getId())->text();
        self::assertStringNotContainsString('jan@example.test', $detail);
    }

    public function testSearchIsCaseInsensitive(): void
    {
        $client = $this->client();
        $this->bug('Grey Tiles On The Map', 'Body.');
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs?q=GREY+tiles');

        self::assertStringContainsString('Grey Tiles On The Map', $page->text());
    }

    /** An underscore in a pasted URL is a character, not a wildcard. */
    public function testWildcardsInTheQueryAreEscaped(): void
    {
        $client = $this->client();
        $this->bug('Broken link', 'It happens on /map_view every time.');
        $this->bug('Another one', 'It happens on /mapXview every time.');
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs?q=map_view')->text();

        self::assertStringContainsString('Broken link', $page);
        self::assertStringNotContainsString('Another one', $page);
    }

    public function testNothingFoundSaysWhatWasSearchedFor(): void
    {
        $client = $this->client();
        $this->bug('The map will not load', 'It stayed grey.');
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs?q=zzznotathing');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('zzznotathing', $page->text());
        self::assertStringNotContainsString('The map will not load', $page->text());
    }

    public function testTheDeskStillDefaultsToNewWithNoSearch(): void
    {
        $client = $this->client();
        $this->bug('A brand new one', 'Body.');
        $this->bug('A resolved one', 'Body.', null, BugStatus::Resolved);
        $client->loginUser($this->curator());

        $page = $client->request('GET', '/moderate/bugs')->text();

        self::assertStringContainsString('A brand new one', $page);
        self::assertStringNotContainsString('A resolved one', $page);
    }
}
