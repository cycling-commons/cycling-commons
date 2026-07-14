<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

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
 * Security review 2026-07-07 (critical #2): the User support-desk actions
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

        // MODERATOR_AREAS is deliberately exempt: it renders a form page (GET)
        // before the curator submits it (POST), unlike every other entry here,
        // which is a one-click mutation with no intermediate page — so it is
        // GET+POST by design, not a CSRF gap (see moderator_areas() handler,
        // which still enforces its own CSRF token check on the POST branch).
        $actions = [
            UserAdminService::UNLOCK, UserAdminService::DISARM_2FA,
            UserAdminService::VERIFY_EMAIL, UserAdminService::UNVERIFY_EMAIL,
            UserAdminService::GRANT_CURATOR, UserAdminService::REVOKE_CURATOR,
            UserAdminService::GRANT_ADMIN, UserAdminService::REVOKE_ADMIN,
            UserAdminService::REMOVE_ACCOUNT, UserAdminService::CANCEL_REMOVAL,
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
