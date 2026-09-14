<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\CountryInterestService;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The demand signal reaches somebody.
 *
 * Until 2026-09-13 `country_interest` was written and never read: a rider
 * could ask for their country, and a rider could offer to curate it, and no
 * page in the project showed either. These pin that the desk exists and that
 * the two things a reviewer acts on, the count and the volunteer, are on it.
 *
 * @see docs/specs/moderation-and-contribution.md §11.1
 */
final class CountryRequestsDeskTest extends WebTestCase
{
    private function admin(KernelBrowser $client): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = new User();
        $u->setEmail('requests-desk@example.test');
        $u->setDisplayName('Requests Desk');
        $u->setPassword('x');
        $u->setEmailVerified(true);
        $u->setRoles(['ROLE_ADMIN']);
        // Fully enrolled, or the 2FA enforcer sends every admin request to
        // /2fa/setup instead of the page under test.
        $u->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function rider(string $email, string $name): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName($name);
        $u->setPassword('x');
        $u->setEmailVerified(true);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    public function testTheDeskShowsTheAreasAskedForAndWhoWouldCurateThem(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin($client), 'main');
        $interests = self::getContainer()->get(CountryInterestService::class);

        $interests->record($this->rider('tex-one@example.test', 'Tex One'), 'US', true, 'Hill Country is empty', 'Texas');
        $interests->record($this->rider('tex-two@example.test', 'Tex Two'), 'US', false, '', 'Texas');
        $interests->record($this->rider('hok@example.test', 'Hok'), 'JP', false, '', 'Hokkaido');

        $html = $client->request('GET', '/admin/country-requests')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Texas', $html, 'the area a rider named is on the page');
        self::assertStringContainsString('Hokkaido', $html);
        self::assertStringContainsString('Hill Country is empty', $html, 'and what they wrote with it');
        self::assertStringContainsString('tex-one@example.test', $html,
            'a volunteer is only useful if the reviewer can write to them');
    }

    public function testACountryNobodyAskedForIsNotOnTheDesk(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin($client), 'main');
        self::getContainer()->get(CountryInterestService::class)
            ->record($this->rider('only-jp@example.test', 'Only JP'), 'JP', false, '', '');

        $html = $client->request('GET', '/admin/country-requests')->html();

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('>Texas<', $html);
    }

    public function testTheDeskIsAdminOnly(): void
    {
        $client = static::createClient();
        $client->loginUser($this->rider('not-admin@example.test', 'Not Admin'), 'main');

        $client->request('GET', '/admin/country-requests');

        self::assertResponseStatusCodeSame(403, 'where riders are asking is a planning signal, not public');
    }
}
