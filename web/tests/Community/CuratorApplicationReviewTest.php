<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\CuratorApplicationService;
use App\Community\CuratorApplicationStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * 2026-07-29-country-requests-and-curator-signup-design.md §9.
 */
final class CuratorApplicationReviewTest extends WebTestCase
{
    private function admin(KernelBrowser $client): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $u = new User();
        $u->setEmail('reviewer@example.test');
        $u->setDisplayName('Reviewer');
        $u->setPassword('x');
        $u->setEmailVerified(true);
        $u->setRoles(['ROLE_ADMIN']);
        // Fully enrolled (secret + enabled), else the 2FA enforcer sends every
        // admin request to /2fa/setup instead of the page under test (see
        // SystemConfigPageTest::createUser).
        $u->setTotpSecret('JBSWY3DPEHPK3PXP');
        $u->setTwoFaEnabled(true);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    public function testApprovingGrantsCuratorScopedToTheCountryAndLogsIt(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('review-pt', 'Portugal', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 900, 'PT', 'PT', 2, 'test', NOW(), NOW())",
        );

        $applicant = new User();
        $applicant->setEmail('applicant@example.test');
        $applicant->setDisplayName('Applicant');
        $applicant->setPassword('x');
        $applicant->setEmailVerified(true);
        $em->persist($applicant);
        $em->flush();

        $app = self::getContainer()->get(CuratorApplicationService::class)
            ->submit($applicant, 'PT', null, null, 'I live in Porto');

        $admin = $this->admin($client);
        $client->loginUser($admin, 'main');

        $crawler = $client->request('GET', '/admin/curator-applications');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/curator-applications', [
            '_token' => $token, 'application' => $app->getId(), 'decision' => 'approve', 'note' => 'knows the area',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects();

        $em->clear();
        $fresh = $em->find(\App\Community\Entity\CuratorApplication::class, $app->getId());
        self::assertSame(CuratorApplicationStatus::Approved, $fresh->getStatus());

        $roles = $db->fetchOne('SELECT roles FROM users WHERE id = ?', [$applicant->getId()]);
        self::assertStringContainsString('ROLE_CURATOR', (string) $roles);

        $area = $db->fetchAssociative('SELECT * FROM moderator_area WHERE user_id = ?', [$applicant->getId()]);
        self::assertIsArray($area, 'approval scopes rather than promotes globally');
        self::assertSame('PT', $area['country_code']);

        $logged = $db->fetchOne(
            'SELECT COUNT(*) FROM admin_action_log WHERE target_user_id = ?',
            [$applicant->getId()],
        );
        self::assertGreaterThan(0, (int) $logged, 'every grant is audited');
    }

    public function testDecliningLeavesTheRoleAloneAndStillNotifies(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('review-gr', 'Greece', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 900, 'GR', 'GR', 2, 'test', NOW(), NOW())",
        );

        $applicant = new User();
        $applicant->setEmail('declined@example.test');
        $applicant->setDisplayName('Declined');
        $applicant->setPassword('x');
        $applicant->setEmailVerified(true);
        $em->persist($applicant);
        $em->flush();

        $app = self::getContainer()->get(CuratorApplicationService::class)
            ->submit($applicant, 'GR', null, null, 'just curious');

        $client->loginUser($this->admin($client), 'main');
        $crawler = $client->request('GET', '/admin/curator-applications');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/curator-applications', [
            '_token' => $token, 'application' => $app->getId(), 'decision' => 'decline', 'note' => 'not yet',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);

        $roles = $db->fetchOne('SELECT roles FROM users WHERE id = ?', [$applicant->getId()]);
        self::assertStringNotContainsString('ROLE_CURATOR', (string) $roles);

        $messages = $db->fetchOne('SELECT COUNT(*) FROM user_message WHERE user_id = ?', [$applicant->getId()]);
        self::assertGreaterThan(0, (int) $messages, 'a decline that arrives as silence teaches people not to volunteer');
    }
}
