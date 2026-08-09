<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\CuratorApplicationException;
use App\Community\CuratorApplicationService;
use App\Community\CuratorApplicationStatus;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * moderation-and-contribution.md.
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

    /**
     * approve() is one transaction end to end: grantCurator()'s role grant
     * must not survive a moderator_area insert that hits
     * uniq_moderator_area(user_id, region_id, country_code) — otherwise the
     * applicant would be left holding ROLE_CURATOR with the application
     * stuck Pending forever, and every retry re-hitting the same conflict.
     *
     * What this asserts, precisely: the failed approve() rolls back BOTH the
     * role grant and the decision (still Pending, roles unchanged), and once
     * the conflicting row is gone, the same application is still
     * re-approvable — from a fresh EntityManager, because Doctrine closes
     * the one that raised the flush exception (its documented behaviour;
     * confirmed empirically here rather than assumed). A real HTTP request
     * always gets a fresh EntityManager, so this only matters in-process
     * inside this test.
     */
    public function testApprovalRollsBackEverythingWhenModeratorAreaConflicts(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('review-conflict', 'Conflictland', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 900, 'CX', 'CX', 2, 'test', NOW(), NOW())",
        );

        $applicant = new User();
        $applicant->setEmail('conflict-applicant@example.test');
        $applicant->setDisplayName('Conflict Applicant');
        $applicant->setPassword('x');
        $applicant->setEmailVerified(true);
        $em->persist($applicant);
        $admin = new User();
        $admin->setEmail('conflict-admin@example.test');
        $admin->setDisplayName('Conflict Admin');
        $admin->setPassword('x');
        $admin->setEmailVerified(true);
        $admin->setRoles(['ROLE_ADMIN']);
        $em->persist($admin);
        $em->flush();

        $svc = self::getContainer()->get(CuratorApplicationService::class);
        $app = $svc->submit($applicant, 'CX', null, null, 'conflict test');

        // Pre-insert the row approve() is about to try to insert: same
        // user_id, null region_id (whole-country), same country_code.
        $db->executeStatement(
            "INSERT INTO moderator_area (user_id, region_id, country_code, created_at) VALUES (?, NULL, 'CX', NOW())",
            [$applicant->getId()],
        );

        $threw = false;
        try {
            $svc->approve($app, $admin, 'first attempt');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $threw = true;
        }
        self::assertTrue($threw, 'the conflict must propagate rather than being swallowed');

        $roles = $db->fetchOne('SELECT roles FROM users WHERE id = ?', [$applicant->getId()]);
        self::assertStringNotContainsString('ROLE_CURATOR', (string) $roles, 'the role grant rolled back with the rest of the transaction');

        $areaCount = (int) $db->fetchOne('SELECT COUNT(*) FROM moderator_area WHERE user_id = ?', [$applicant->getId()]);
        self::assertSame(1, $areaCount, 'no duplicate row was left behind; only the pre-existing conflicting one remains');

        $status = $db->fetchOne('SELECT status FROM curator_application WHERE id = ?', [$app->getId()]);
        self::assertSame('pending', $status, 'the decision itself rolled back too, so the application is still re-approvable');

        // Clear the conflict and retry, from a fresh EntityManager (the one
        // that just raised the flush exception is closed by Doctrine).
        $db->executeStatement('DELETE FROM moderator_area WHERE user_id = ?', [$applicant->getId()]);

        /** @var \Doctrine\Persistence\ManagerRegistry $registry */
        $registry = self::getContainer()->get('doctrine');
        $registry->resetManager();
        $freshEm = $registry->getManager();
        \assert($freshEm instanceof EntityManagerInterface);
        $freshApp = $freshEm->find(\App\Community\Entity\CuratorApplication::class, $app->getId());
        self::assertNotNull($freshApp);
        $freshAdmin = $freshEm->find(User::class, $admin->getId());
        self::assertNotNull($freshAdmin);

        self::getContainer()->get(CuratorApplicationService::class)->approve($freshApp, $freshAdmin, 'retry after clearing the conflict');

        $rolesAfterRetry = $db->fetchOne('SELECT roles FROM users WHERE id = ?', [$applicant->getId()]);
        self::assertStringContainsString('ROLE_CURATOR', (string) $rolesAfterRetry, 'the retry succeeds once the conflict is gone');
        $statusAfterRetry = $db->fetchOne('SELECT status FROM curator_application WHERE id = ?', [$app->getId()]);
        self::assertSame('approved', $statusAfterRetry);
    }

    /**
     * A stale page — two admin tabs, a double submit — must not silently
     * re-run a decision. Approve, then attempt to decline the same
     * application: the status and role from the first decision must be
     * untouched, and the second request must take the flash-and-no-op path
     * rather than reprocessing.
     */
    public function testRedecidingAnAlreadyDecidedApplicationIsANoOp(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('review-redecide', 'Redecideland', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 900, 'RD', 'RD', 2, 'test', NOW(), NOW())",
        );

        $applicant = new User();
        $applicant->setEmail('redecide-applicant@example.test');
        $applicant->setDisplayName('Redecide Applicant');
        $applicant->setPassword('x');
        $applicant->setEmailVerified(true);
        $em->persist($applicant);
        $em->flush();

        $app = self::getContainer()->get(CuratorApplicationService::class)
            ->submit($applicant, 'RD', null, null, 'redecide test');

        $client->loginUser($this->admin($client), 'main');
        $crawler = $client->request('GET', '/admin/curator-applications');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/curator-applications', [
            '_token' => $token, 'application' => $app->getId(), 'decision' => 'approve', 'note' => 'approved first',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects();

        // Second request, same application, opposite decision. The CSRF
        // token is session-backed and keyed by token id rather than by row,
        // so the same $token is still valid for the rest of this session —
        // and it must be reused here: the application is Approved now, so it
        // no longer has a row (and thus no fresh token to scrape) on the
        // page at all.
        $client->request('POST', '/admin/curator-applications', [
            '_token' => $token, 'application' => $app->getId(), 'decision' => 'decline', 'note' => 'too late',
        ], [], ['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'already decided');

        $status = $db->fetchOne('SELECT status FROM curator_application WHERE id = ?', [$app->getId()]);
        self::assertSame('approved', $status, 'the second, conflicting decision never applied');

        $roles = $db->fetchOne('SELECT roles FROM users WHERE id = ?', [$applicant->getId()]);
        self::assertStringContainsString('ROLE_CURATOR', (string) $roles, 'the role from the first decision is untouched');
    }

    /**
     * requested_region_id carries no FK (unlike moderator_area.region_id,
     * which does — ON DELETE CASCADE), so a region deleted after submission
     * does not null the column out; it dangles. approve() must catch that
     * itself rather than either FK-500ing on the moderator_area insert or,
     * worse, succeeding and leaving a dangling id in a row with no FK to
     * catch it later.
     */
    public function testApprovingADeletedRequestedRegionIsBlockedCleanly(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('review-gone', 'Goneland', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 900, 'GN', 'GN', 2, 'test', NOW(), NOW())",
        );
        $regionId = (int) $db->lastInsertId('region_id_seq');

        $applicant = new User();
        $applicant->setEmail('region-gone-applicant@example.test');
        $applicant->setDisplayName('Region Gone Applicant');
        $applicant->setPassword('x');
        $applicant->setEmailVerified(true);
        $em->persist($applicant);
        $admin = new User();
        $admin->setEmail('region-gone-admin@example.test');
        $admin->setDisplayName('Region Gone Admin');
        $admin->setPassword('x');
        $admin->setEmailVerified(true);
        $admin->setRoles(['ROLE_ADMIN']);
        $em->persist($admin);
        $em->flush();

        $svc = self::getContainer()->get(CuratorApplicationService::class);
        $app = $svc->submit($applicant, 'GN', $regionId, null, 'I curate this one region');

        // Legal at the DB level precisely because requested_region_id has no FK.
        $db->executeStatement('DELETE FROM region WHERE id = ?', [$regionId]);

        $threw = false;
        try {
            $svc->approve($app, $admin, null);
        } catch (CuratorApplicationException $e) {
            $threw = true;
            self::assertSame('region_gone', $e->reason);
        }
        self::assertTrue($threw, 'approve() must refuse cleanly rather than FK-500 or insert a dangling region id');

        self::assertSame('pending', $db->fetchOne('SELECT status FROM curator_application WHERE id = ?', [$app->getId()]), 'the application is still re-decidable');
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM moderator_area WHERE user_id = ?', [$applicant->getId()]), 'no area row — dangling or otherwise — was inserted');
    }

    /**
     * map-and-search.md §4.5: a region requested while
     * operational can become infrastructure-only between submission and
     * approval — a country onboarding a deeper level demotes its previous
     * operating level the moment the finer rows land. approve() must treat
     * that the same as a deleted region (`region_gone`) rather than granting
     * a scope that can never appear on any public or moderation surface.
     */
    public function testApprovingARegionThatBecameInfrastructureSinceSubmissionIsBlockedCleanly(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('review-demoted', 'Demotedland', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 900, 'ZK', 'ZK', 4, 'test', NOW(), NOW())",
        );
        $regionId = (int) $db->lastInsertId('region_id_seq');

        $applicant = new User();
        $applicant->setEmail('demoted-applicant@example.test');
        $applicant->setDisplayName('Demoted Applicant');
        $applicant->setPassword('x');
        $applicant->setEmailVerified(true);
        $em->persist($applicant);
        $admin = new User();
        $admin->setEmail('demoted-admin@example.test');
        $admin->setDisplayName('Demoted Admin');
        $admin->setPassword('x');
        $admin->setEmailVerified(true);
        $admin->setRoles(['ROLE_ADMIN']);
        $em->persist($admin);
        $em->flush();

        $svc = self::getContainer()->get(CuratorApplicationService::class);
        // At submission time, admin_level=4 is the deepest ZK row, so it is
        // operational and the request is accepted.
        $app = $svc->submit($applicant, 'ZK', $regionId, null, 'I curate this one region');
        self::assertSame($regionId, $app->getRequestedRegionId(), 'operational at submission time, so the request is accepted');

        // A deeper level lands for the same country, demoting the requested
        // region to infrastructure — no schema change, purely derived.
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('review-demoted-deeper', 'Demotedland deeper', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 100, 'ZK', 'ZK-DEEP', 6, 'test', NOW(), NOW())",
        );

        $threw = false;
        try {
            $svc->approve($app, $admin, null);
        } catch (CuratorApplicationException $e) {
            $threw = true;
            self::assertSame('region_gone', $e->reason);
        }
        self::assertTrue($threw, 'approve() must refuse a region that is no longer operational, same as a deleted one');

        self::assertSame('pending', $db->fetchOne('SELECT status FROM curator_application WHERE id = ?', [$app->getId()]), 'the application is still re-decidable');
        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM moderator_area WHERE user_id = ?', [$applicant->getId()]), 'no area row was inserted for a scope that could never appear anywhere');
    }

    /**
     * §9: approval SCOPES rather than promotes. Someone already holding
     * ROLE_CURATOR with zero moderator_area rows is GLOBAL (ModeratorArea's
     * own docblock: "no rows = global") — approving an unrelated,
     * necessarily narrower, country/region request for them must not
     * silently demote a global curator. That is a decision for a human, not
     * a side effect of clicking Approve on a different application.
     */
    public function testApprovingAnAlreadyGlobalCuratorIsBlockedRatherThanNarrowingThem(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $db = $em->getConnection();
        $db->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('review-global', 'Globalland', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 900, 'GL', 'GL', 2, 'test', NOW(), NOW())",
        );

        $applicant = new User();
        $applicant->setEmail('already-global@example.test');
        $applicant->setDisplayName('Already Global');
        $applicant->setPassword('x');
        $applicant->setEmailVerified(true);
        // Already a global curator: ROLE_CURATOR with zero moderator_area rows.
        $applicant->setRoles(['ROLE_CURATOR']);
        $em->persist($applicant);
        $admin = new User();
        $admin->setEmail('already-global-admin@example.test');
        $admin->setDisplayName('Already Global Admin');
        $admin->setPassword('x');
        $admin->setEmailVerified(true);
        $admin->setRoles(['ROLE_ADMIN']);
        $em->persist($admin);
        $em->flush();

        $svc = self::getContainer()->get(CuratorApplicationService::class);
        $app = $svc->submit($applicant, 'GL', null, null, 'I would like to formally join here too');

        $threw = false;
        try {
            $svc->approve($app, $admin, null);
        } catch (CuratorApplicationException $e) {
            $threw = true;
            self::assertSame('already_global_curator', $e->reason);
        }
        self::assertTrue($threw, 'approving must not silently narrow an existing global curator');

        self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) FROM moderator_area WHERE user_id = ?', [$applicant->getId()]), 'no narrowing row was inserted');
        self::assertSame('pending', $db->fetchOne('SELECT status FROM curator_application WHERE id = ?', [$app->getId()]), 'the application is still re-decidable');
    }

    /**
     * The review page pages, and the pager counts the set it is paging. Only
     * PENDING applications are on it — a decided one must leave both the list
     * and the count, or the reviewer's "3 in total" outlives their work.
     */
    public function testTheReviewPagePagesAndCountsOnlyWhatIsWaiting(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $svc = self::getContainer()->get(CuratorApplicationService::class);
        $em->getConnection()->executeStatement(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, iso_code, admin_level, source, created_at, updated_at)
             VALUES ('paging-pt', 'Portugal', ST_GeomFromText('POLYGON((0 0,0 1,1 1,1 0,0 0))', 4326), 900, 'PT', 'PT', 2, 'test', NOW(), NOW())",
        );

        $apps = [];
        for ($i = 0; $i < 3; ++$i) {
            $applicant = new User();
            $applicant->setEmail(sprintf('paging-applicant-%d@example.test', $i))->setDisplayName('Paging Applicant '.$i);
            $applicant->setPassword('x');
            $applicant->setEmailVerified(true);
            $em->persist($applicant);
            $em->flush();
            $apps[] = $svc->submit($applicant, 'PT', null, null, 'I would like to help out here');
        }

        self::assertSame(3, $svc->pendingCount());
        self::assertCount(2, $svc->pending(1, 2));
        self::assertCount(1, $svc->pending(2, 2));

        $svc->decline($apps[0], $this->admin($client), 'Not this time.');

        self::assertSame(2, $svc->pendingCount(), 'a decided application leaves the count with the list');
        self::assertCount(2, $svc->pending(1, 25));
    }
}
