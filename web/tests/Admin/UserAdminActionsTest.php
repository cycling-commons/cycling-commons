<?php

// SPDX-License-Identifier: AGPL-3.0-only

namespace App\Tests\Admin;

use App\Catalog\Entity\Region;
use App\Controller\Admin\DashboardController;
use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use App\Moderation\Entity\ModeratorArea;
use App\Repository\AdminActionLogRepository;
use App\Repository\UserRepository;
use App\Service\UserAdminService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The User support-desk actions
 * (unlock / disarm 2FA / grant-revoke roles / remove account) used to render
 * as GET `<a href>` links with no CSRF token and no method guard, so a
 * cross-site page could trigger privilege escalation or account destruction
 * via a top-level navigation. They must now be POST-only, CSRF-protected forms,
 * and the built-in EasyAdmin EDIT/DELETE (which bypass UserAdminService's
 * guardrails + audit log) must be disabled.
 */
final class UserAdminActionsTest extends WebTestCase
{
    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = [], bool $admin2fa = false): User
    {
        $c = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $c->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $c->get(EntityManagerInterface::class);

        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles($roles);
        $u->setPassword($hasher->hashPassword($u, 'password1234'));
        if ($admin2fa) {
            $u->setTotpSecret('JBSWY3DPEHPK3PXP');
            $u->setTwoFaEnabled(true); // fully enrolled (secret + enabled), else the enforcer sends them to /2fa/setup
        }
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function actionUrl(string $action, int $entityId): string
    {
        /** @var AdminUrlGenerator $gen */
        $gen = static::getContainer()->get(AdminUrlGenerator::class);

        return $gen->setDashboard(DashboardController::class)
            ->setController(UserCrudController::class)
            ->setAction($action)
            ->setEntityId($entityId)
            ->generateUrl();
    }

    private function detailCrawler(KernelBrowser $client, int $entityId): Crawler
    {
        /** @var AdminUrlGenerator $gen */
        $gen = static::getContainer()->get(AdminUrlGenerator::class);
        $url = $gen->setDashboard(DashboardController::class)
            ->setController(UserCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($entityId)
            ->generateUrl();

        return $client->request('GET', $url);
    }

    private function reload(string $email): User
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $u = static::getContainer()->get(UserRepository::class)->findByEmail($email);
        self::assertNotNull($u);

        return $u;
    }

    // ── CSRF / method hardening ──────────────────────────────────────────────

    public function testEverySupportActionRouteIsPostOnly(): void
    {
        // The routing contract IS the CSRF/GET defence: Symfony will not match a
        // GET to a POST-only route, so a cross-site top-level navigation (which
        // can only issue a GET, and under SameSite=Lax is the only cross-site
        // request that carries the session cookie) can never reach these
        // account-mutating handlers.
        static::createClient();
        $router = static::getContainer()->get('router');

        // MODERATOR_AREAS, GRANT_CURATOR, SUSPEND and REMOVE_FOR_BREACH are deliberately exempt: each
        // renders a form page (GET) before the admin submits it (POST), unlike
        // every other entry here, which is a one-click mutation with no
        // intermediate page, so they are GET+POST by design, not a CSRF gap
        // (both handlers still check the CSRF token on the POST branch).
        $actions = [
            UserAdminService::UNLOCK, UserAdminService::DISARM_2FA,
            UserAdminService::VERIFY_EMAIL, UserAdminService::UNVERIFY_EMAIL,
            UserAdminService::REVOKE_CURATOR,
            UserAdminService::GRANT_ADMIN, UserAdminService::REVOKE_ADMIN,
            UserAdminService::REMOVE_ACCOUNT, UserAdminService::CANCEL_REMOVAL,
            UserAdminService::LIFT_SUSPENSION,
        ];
        foreach ($actions as $action) {
            $route = $router->getRouteCollection()->get('admin_user_'.$action);
            self::assertNotNull($route, "route admin_user_{$action} must exist");
            self::assertSame(['POST'], $route->getMethods(), "admin_user_{$action} must be POST-only");
        }
    }

    public function testSupportActionRejectsPostWithoutCsrfToken(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $locked = $this->createUser('locked@example.com');
        $locked->setFailedLoginAttempts(5);
        $locked->setLockedUntil(new \DateTimeImmutable('+1 hour'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($admin);
        // A forged POST with no (or wrong) CSRF token must be rejected.
        $client->request('POST', $this->actionUrl(UserAdminService::UNLOCK, (int) $locked->getId()), ['token' => 'forged']);

        self::assertTrue($this->reload('locked@example.com')->isLocked(), 'a tokenless/forged POST must never unlock the account');
    }

    public function testUnlockViaTheRenderedFormClearsLockAndWritesAudit(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $locked = $this->createUser('locked@example.com');
        $locked->setFailedLoginAttempts(5);
        $locked->setLockedUntil(new \DateTimeImmutable('+1 hour'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($admin);
        $crawler = $this->detailCrawler($client, (int) $locked->getId());
        self::assertResponseIsSuccessful();

        // Submit the real rendered form — it carries a valid CSRF token.
        $client->submit($crawler->selectButton('Unlock account')->form());
        self::assertResponseRedirects();

        self::assertFalse($this->reload('locked@example.com')->isLocked());

        $logs = static::getContainer()->get(AdminActionLogRepository::class)
            ->findBy(['action' => UserAdminService::UNLOCK]);
        self::assertCount(1, $logs, 'unlock action must write exactly one audit row');
    }

    public function testRevokingLastAdminIsBlockedWithFlash(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('solo-admin@example.com', ['ROLE_ADMIN'], admin2fa: true);

        $client->loginUser($admin);
        $crawler = $this->detailCrawler($client, (int) $admin->getId());
        self::assertResponseIsSuccessful();

        $client->submit($crawler->selectButton('Revoke admin')->form());
        $client->followRedirect();

        self::assertSelectorExists('.alert-danger, .flash-danger, [class*="danger"]');
        self::assertContains('ROLE_ADMIN', $this->reload('solo-admin@example.com')->getRoles(), 'Last admin must keep ROLE_ADMIN.');
    }

    // ── Built-in EDIT/DELETE lockdown (they bypass UserAdminService) ─────────

    public function testBuiltInEditAndDeleteAreDisabledOnTheUserCrud(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $subject = $this->createUser('subject@example.com');

        $client->loginUser($admin);
        $this->detailCrawler($client, (int) $subject->getId());

        self::assertResponseIsSuccessful();
        // The generic EA edit/delete would let an admin change roles / delete the
        // row without the UserAdminService guardrails + audit — they must be gone.
        self::assertSelectorNotExists('.action-edit');
        self::assertSelectorNotExists('.action-delete');

        // …and not merely hidden: executing the disabled EDIT action is forbidden.
        $gen = static::getContainer()->get(AdminUrlGenerator::class);
        $editUrl = $gen->setDashboard(DashboardController::class)
            ->setController(UserCrudController::class)
            ->setAction(Action::EDIT)
            ->setEntityId((int) $subject->getId())
            ->generateUrl();
        $client->request('GET', $editUrl);
        self::assertResponseStatusCodeSame(403, 'the disabled EDIT action must not be executable');
    }

    public function testDetailPageNeverLeaksSecrets(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $t = $this->createUser('subject@example.com');
        $t->setTotpSecret('JBSWY3DPEHPK3PXPSECRETSEED');
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->loginUser($admin);
        $this->detailCrawler($client, (int) $t->getId());

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXPSECRETSEED', (string) $client->getResponse()->getContent());
    }

    public function testARiderWhoIsNoCuratorHasNoModerationAreas(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin-areas@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $rider = $this->createUser('rider-areas@example.com');
        $curator = $this->createUser('curator-areas@example.com', ['ROLE_CURATOR']);

        $client->loginUser($admin);
        $page = $this->detailCrawler($client, (int) $rider->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Not a curator', $page->text());
        self::assertStringNotContainsString('All areas', $page->text(), 'a rider moderates nothing');

        $page = $this->detailCrawler($client, (int) $curator->getId());
        self::assertStringContainsString('All areas', $page->text(), 'a curator without areas moderates everywhere (§9.2)');
    }

    // ── Grant curator: areas first (moderation-and-contribution.md §9.4) ────

    /** @param array<string, mixed> $fields */
    private function postGrant(KernelBrowser $client, User $target, array $fields): void
    {
        $crawler = $client->request('GET', $this->actionUrl(UserAdminService::GRANT_CURATOR, (int) $target->getId()));
        $token = (string) $crawler->filter('form input[name="token"]')->attr('value');
        $client->request('POST', $this->actionUrl(UserAdminService::GRANT_CURATOR, (int) $target->getId()), ['token' => $token] + $fields);
    }

    public function testGrantCuratorOpensTheAreaPickerAndGrantsNothingYet(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $rider = $this->createUser('rider@example.com');

        $client->loginUser($admin);
        $detail = $this->detailCrawler($client, (int) $rider->getId());
        $link = $detail->filter('a.action-'.UserAdminService::GRANT_CURATOR);
        self::assertCount(1, $link, 'Grant curator opens a page, it is no longer a one-click form');

        $client->request('GET', (string) $link->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form select[name="regions[]"]');
        self::assertSelectorExists('form select[name="countries[]"]');
        self::assertSelectorExists('form input[type="checkbox"][name="all_areas"]');
        self::assertNotContains('ROLE_CURATOR', $this->reload('rider@example.com')->getRoles());
    }

    public function testGrantCuratorWithACountrySavesRoleAndArea(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $rider = $this->createUser('rider@example.com');

        $client->loginUser($admin);
        $this->postGrant($client, $rider, ['countries' => ['NL']]);
        self::assertResponseRedirects();

        self::assertContains('ROLE_CURATOR', $this->reload('rider@example.com')->getRoles());
        $rows = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ModeratorArea::class)->findBy(['userId' => (int) $rider->getId()]);
        self::assertCount(1, $rows);
        self::assertSame('NL', $rows[0]->getCountryCode());
    }

    public function testGrantCuratorForAllAreasNeedsTheTick(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $rider = $this->createUser('rider@example.com');

        $client->loginUser($admin);
        $this->postGrant($client, $rider, ['all_areas' => '1']);
        self::assertResponseRedirects();

        self::assertContains('ROLE_CURATOR', $this->reload('rider@example.com')->getRoles());
        $rows = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ModeratorArea::class)->findBy(['userId' => (int) $rider->getId()]);
        self::assertCount(0, $rows, 'All areas means no area rows (§9.2)');
    }

    public function testGrantCuratorWithoutAClearChoiceGrantsNothing(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $rider = $this->createUser('rider@example.com');
        $client->loginUser($admin);

        foreach ([[], ['all_areas' => '1', 'countries' => ['NL']]] as $fields) {
            $this->postGrant($client, $rider, $fields);
            $client->followRedirect();
            self::assertSelectorExists('.alert-danger, .flash-danger, [class*="danger"]');
            self::assertNotContains('ROLE_CURATOR', $this->reload('rider@example.com')->getRoles());
        }
        $logs = static::getContainer()->get(AdminActionLogRepository::class)
            ->findBy(['action' => UserAdminService::GRANT_CURATOR]);
        self::assertCount(0, $logs);
    }

    public function testGrantCuratorRejectsPostWithoutCsrfToken(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $rider = $this->createUser('rider@example.com');

        $client->loginUser($admin);
        $client->request('POST', $this->actionUrl(UserAdminService::GRANT_CURATOR, (int) $rider->getId()), ['token' => 'forged', 'all_areas' => '1']);

        self::assertNotContains('ROLE_CURATOR', $this->reload('rider@example.com')->getRoles());
    }

    // ── Suspension and removal for a breach (account-and-auth.md §6.8) ──────

    public function testSuspendOpensAFormAndSuspendsWithTheReasons(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $rider = $this->createUser('rider@example.com');
        $client->loginUser($admin);

        $link = $this->detailCrawler($client, (int) $rider->getId())->filter('a.action-'.UserAdminService::SUSPEND);
        self::assertCount(1, $link, 'Suspend opens a form page');
        $page = $client->request('GET', (string) $link->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="days"]');
        self::assertSelectorExists('form select[name="ground"] option[value="abuse"]');
        self::assertSelectorNotExists('form select[name="ground"] option[value="not_accepted"]');
        self::assertSelectorExists('form textarea[name="facts"]');
        self::assertFalse($this->reload('rider@example.com')->isSuspendedAt(new \DateTimeImmutable()), 'opening the form suspends nothing');

        $client->request('POST', $this->actionUrl(UserAdminService::SUSPEND, (int) $rider->getId()), [
            'token' => (string) $page->filter('form input[name="token"]')->attr('value'),
            'days' => '7',
            'ground' => 'abuse',
            'facts' => 'Threats to other riders.',
        ]);
        self::assertResponseRedirects();
        self::assertTrue($this->reload('rider@example.com')->isSuspendedAt(new \DateTimeImmutable()));

        $detail = $this->detailCrawler($client, (int) $rider->getId());
        self::assertCount(1, $detail->filter('button.action-'.UserAdminService::LIFT_SUSPENSION.', form button:contains("Lift suspension")'));
    }

    public function testASuspensionWithoutFactsIsRefusedWithAFlash(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $rider = $this->createUser('rider@example.com');
        $client->loginUser($admin);

        $page = $client->request('GET', $this->actionUrl(UserAdminService::SUSPEND, (int) $rider->getId()));
        $client->request('POST', $this->actionUrl(UserAdminService::SUSPEND, (int) $rider->getId()), [
            'token' => (string) $page->filter('form input[name="token"]')->attr('value'),
            'days' => '7',
            'ground' => 'abuse',
            'facts' => '',
        ]);
        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('[class*="danger"]', 'Write the facts');
        self::assertFalse($this->reload('rider@example.com')->isSuspendedAt(new \DateTimeImmutable()));
    }

    public function testRemoveForABreachDeletesTheAccount(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $rider = $this->createUser('rider@example.com');
        $client->loginUser($admin);

        $link = $this->detailCrawler($client, (int) $rider->getId())->filter('a.action-'.UserAdminService::REMOVE_FOR_BREACH);
        self::assertCount(1, $link);
        $page = $client->request('GET', (string) $link->attr('href'));
        self::assertResponseIsSuccessful();
        $client->request('POST', $this->actionUrl(UserAdminService::REMOVE_FOR_BREACH, (int) $rider->getId()), [
            'token' => (string) $page->filter('form input[name="token"]')->attr('value'),
            'ground' => 'misuse',
            'facts' => 'Scraped the map with a script.',
        ]);
        self::assertResponseRedirects();

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull(static::getContainer()->get(UserRepository::class)->findByEmail('rider@example.com'));
    }

    // ── Moderator areas (assign) ─────────────────────────────────────────────

    public function testAssignModeratorAreasReplacesRowsAndAudits(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $target = $this->createUser('curator@example.com', ['ROLE_CURATOR']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = new Region();
        $region->setSlug('wallonia')->setName('Wallonia')->setCountryCode('BE');
        $em->persist($region);
        $em->flush();
        $regionId = (int) $region->getId();

        $client->loginUser($admin);

        $crawler = $client->request('GET', $this->actionUrl(UserAdminService::MODERATOR_AREAS, (int) $target->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form select[name="regions[]"]');
        self::assertSelectorExists('form select[name="countries[]"]');
        $token = (string) $crawler->filter('form input[name="token"]')->attr('value');
        self::assertNotSame('', $token);

        // First POST: one region + one country → two rows.
        $client->request('POST', $this->actionUrl(UserAdminService::MODERATOR_AREAS, (int) $target->getId()), [
            'token' => $token,
            'regions' => [$regionId],
            'countries' => ['NL'],
        ]);
        self::assertResponseRedirects();

        $em->clear();
        $rows = $em->getRepository(ModeratorArea::class)->findBy(['userId' => (int) $target->getId()]);
        self::assertCount(2, $rows, 'assigning a region + a country must write exactly two rows');

        // Second POST: only a country → the previous rows are REPLACED, not appended.
        $client->request('POST', $this->actionUrl(UserAdminService::MODERATOR_AREAS, (int) $target->getId()), [
            'token' => $token,
            'countries' => ['BE'],
        ]);
        self::assertResponseRedirects();

        $em->clear();
        $rows = $em->getRepository(ModeratorArea::class)->findBy(['userId' => (int) $target->getId()]);
        self::assertCount(1, $rows, 'a second assignment must replace the prior rows, not add to them');
        self::assertSame('BE', $rows[0]->getCountryCode());

        $logs = static::getContainer()->get(AdminActionLogRepository::class)
            ->findBy(['action' => UserAdminService::MODERATOR_AREAS], ['id' => 'ASC']);
        self::assertCount(2, $logs, 'each assignment call must write exactly one audit row');
        self::assertStringContainsString('BE', (string) $logs[1]->getNote());
        // Regression guard on the empty-side fallback text: the country-only
        // assignment must record its region side as the literal 'none'.
        self::assertStringContainsString('regions: none', (string) $logs[1]->getNote());
    }

    public function testClearingAllAreasRemovesRowsAndAuditsNone(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $target = $this->createUser('curator@example.com', ['ROLE_CURATOR']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = new Region();
        $region->setSlug('wallonia')->setName('Wallonia')->setCountryCode('BE');
        $em->persist($region);
        $em->flush();
        $regionId = (int) $region->getId();

        $client->loginUser($admin);

        $crawler = $client->request('GET', $this->actionUrl(UserAdminService::MODERATOR_AREAS, (int) $target->getId()));
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form input[name="token"]')->attr('value');

        // Seed an assignment first, so clearing genuinely removes rows.
        $client->request('POST', $this->actionUrl(UserAdminService::MODERATOR_AREAS, (int) $target->getId()), [
            'token' => $token,
            'regions' => [$regionId],
            'countries' => ['NL'],
        ]);
        self::assertResponseRedirects();
        $em->clear();
        self::assertCount(2, $em->getRepository(ModeratorArea::class)->findBy(['userId' => (int) $target->getId()]));

        // Clear-all: POST with NEITHER regions[] nor countries[] → back to global.
        $client->request('POST', $this->actionUrl(UserAdminService::MODERATOR_AREAS, (int) $target->getId()), [
            'token' => $token,
        ]);
        self::assertResponseRedirects();

        $em->clear();
        $rows = $em->getRepository(ModeratorArea::class)->findBy(['userId' => (int) $target->getId()]);
        self::assertCount(0, $rows, 'clearing the assignment must remove every moderator_area row');

        $logs = static::getContainer()->get(AdminActionLogRepository::class)
            ->findBy(['action' => UserAdminService::MODERATOR_AREAS], ['id' => 'ASC']);
        self::assertCount(2, $logs, 'the clear-all call must write its own audit row');
        self::assertSame('regions: none; countries: none', (string) $logs[1]->getNote());
    }

    public function testAssignAreasRejectsUnknownRegion(): void
    {
        $client = static::createClient();
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true);
        $target = $this->createUser('curator@example.com', ['ROLE_CURATOR']);

        $client->loginUser($admin);

        $crawler = $client->request('GET', $this->actionUrl(UserAdminService::MODERATOR_AREAS, (int) $target->getId()));
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('form input[name="token"]')->attr('value');

        $client->request('POST', $this->actionUrl(UserAdminService::MODERATOR_AREAS, (int) $target->getId()), [
            'token' => $token,
            'regions' => [999999],
        ]);
        $client->followRedirect();

        self::assertSelectorExists('.alert-danger, .flash-danger, [class*="danger"]');

        $rows = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(ModeratorArea::class)->findBy(['userId' => (int) $target->getId()]);
        self::assertCount(0, $rows, 'a rejected assignment must never write rows');
    }
}
