<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\User;
use App\Repository\AdminActionLogRepository;
use App\Settings\SettingDefinition;
use App\Settings\SettingsRegistry;
use App\Settings\SystemSettings;
use App\Settings\SystemSettingsWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The admin system-config page (system-configuration.md §4): the owner's
 * "set all variables like 25 / 3 / 5" surface. Six typed, grouped,
 * range-checked fields, a reset per row, and an audit entry per change — not an
 * EasyAdmin CRUD over a settings entity.
 */
final class SystemConfigPageTest extends WebTestCase
{
    private const string CAP = SettingsRegistry::ROUTE_REGION_ACTIVE_CAP;

    /** @param list<string> $roles */
    private function createUser(string $email, array $roles = [], bool $admin2fa = false): User
    {
        $c = static::getContainer();
        $u = new User();
        $u->setEmail($email);
        $u->setDisplayName(strstr($email, '@', true) ?: $email);
        $u->setEmailVerified(true);
        $u->setEmailVerifiedAt(new \DateTimeImmutable());
        $u->setRoles($roles);
        $u->setPassword($c->get(UserPasswordHasherInterface::class)->hashPassword($u, 'password1234'));
        if ($admin2fa) {
            // Fully enrolled (secret + enabled), else the 2FA enforcer sends
            // every admin request to /2fa/setup instead of the page under test.
            $u->setTotpSecret('JBSWY3DPEHPK3PXP');
            $u->setTwoFaEnabled(true);
        }
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function url(): string
    {
        return static::getContainer()->get('router')->generate('admin_system_config');
    }

    private function settings(): SystemSettings
    {
        return static::getContainer()->get(SystemSettings::class);
    }

    /** Read the default off the registry, so tuning a YAML number never breaks a test that is not about that number. */
    private function defaultOf(string $key): int
    {
        return static::getContainer()->get(SettingsRegistry::class)->get($key)->default;
    }

    /**
     * The CSRF token is stateless and same-origin: minted into the template and
     * read back out of the rendered page, because getToken() outside a request
     * has no session to live in.
     */
    private function tokenFrom(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', $this->url());
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('input[name="token"]')->attr('value');
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function testAnonymousVisitorsAreSentToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', $this->url());

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
    }

    public function testANonAdminIsForbidden(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('curator@example.com', ['ROLE_CURATOR']));
        $client->request('GET', $this->url());

        self::assertResponseStatusCodeSame(403);
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    public function testThePageRendersEveryConfigurableSettingAndNothingElse(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $crawler = $client->request('GET', $this->url());

        self::assertResponseIsSuccessful();

        /** @var SettingsRegistry $registry */
        $registry = static::getContainer()->get(SettingsRegistry::class);
        foreach ($registry->all() as $key => $def) {
            $input = $crawler->filter(sprintf('input[name="settings[%s]"]', $key));
            self::assertCount(1, $input, "a field for {$key} must render");
            self::assertSame((string) $def->default, $input->attr('value'));
            // Two input types, two sets of attributes (SettingDefinition): a
            // spinner is bounded by min/max, a text box by maxlength.
            if ($def->isString()) {
                self::assertSame((string) $def->maxLength, $input->attr('maxlength'));
            } else {
                self::assertSame((string) $def->min, $input->attr('min'));
                self::assertSame((string) $def->max, $input->attr('max'));
            }
        }

        // The three coverage.* values are infrastructure and stay env-backed
        // (owner decision 2026-07-29) — a CSP host must never be editable from
        // a web form, so it must not have a field here.
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('coverage.csp_host', $html);
        self::assertStringNotContainsString('coverage.manifest_url', $html);
        self::assertStringNotContainsString('coverage.tiles_enabled', $html);
    }

    // ── Saving ───────────────────────────────────────────────────────────────

    /**
     * A setting may not be saved empty, whatever its type, and the page has to
     * say that, rather than answering an empty box with a complaint about a
     * number nobody typed.
     *
     * The exception is a setting that declares emptiness a MEANING rather than
     * an omission ({@see SettingDefinition::$allowsEmpty}). Only the support
     * recipients do, where blank means "fall back to the alert recipients"
     * (contact-and-support.md §7). Those are covered by the test below.
     */
    public function testAnEmptyFieldIsRefusedWithItsOwnMessage(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $registry = static::getContainer()->get(SettingsRegistry::class);

        foreach ($registry->all() as $key => $def) {
            if ($def->allowsEmpty) {
                continue;
            }
            $crawler = $client->request('GET', $this->url());
            $form = $crawler->selectButton('Save settings')->form();
            $form['settings['.$key.']'] = '';
            $crawler = $client->submit($form);

            self::assertResponseIsSuccessful("{$key}: an empty value must not save");
            self::assertStringContainsString('This cannot be empty', $crawler->html(), "{$key}: says why");
            self::assertFalse($this->settings()->isOverridden($key), "{$key}: nothing was written");
        }
    }

    /**
     * A setting that declares emptiness a meaning can actually be cleared.
     *
     * Before `allowsEmpty` existed the desk hard-coded "required", so a setting
     * whose blank value is deliberate could be rendered but never saved. That
     * is the kind of gap nobody finds until somebody tries to change it during
     * an incident.
     */
    public function testASettingThatAllowsEmptyCanBeSavedEmpty(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $registry = static::getContainer()->get(SettingsRegistry::class);

        $allowEmpty = array_filter($registry->all(), static fn ($def): bool => $def->allowsEmpty);
        self::assertNotSame([], $allowEmpty, 'at least one setting is expected to allow empty');

        foreach ($allowEmpty as $key => $_def) {
            // Put a value in first, so clearing it is a real change.
            $crawler = $client->request('GET', $this->url());
            $form = $crawler->selectButton('Save settings')->form();
            $form['settings['.$key.']'] = 'desk@example.test';
            $client->submit($form);
            self::assertResponseRedirects('', null, "{$key}: a value must save");

            $crawler = $client->request('GET', $this->url());
            $form = $crawler->selectButton('Save settings')->form();
            $form['settings['.$key.']'] = '';
            $client->submit($form);

            self::assertResponseRedirects('', null, "{$key}: clearing it must save too");
            self::assertSame('', $this->settings()->getString($key), "{$key}: the empty value is what is stored");
        }
    }

    /**
     * The reset button carries formnovalidate. Without it the browser's own
     * `required` check refuses to submit while any field is empty — and the
     * row an admin is trying to put back is usually that very field, so the
     * page would appear stuck with no way out.
     */
    public function testResetStaysUsableWhileAnotherFieldIsEmpty(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $crawler = $client->request('GET', $this->url());

        // A valueless HTML attribute reads back as '' rather than its own
        // name, so presence is the thing to assert.
        self::assertNotNull(
            $crawler->filter('button[name="reset"]')->first()->attr('formnovalidate'),
            'the reset button must opt out of client-side validation',
        );
    }

    public function testSavingTheRenderedFormChangesTheValueAndAudits(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $crawler = $client->request('GET', $this->url());
        self::assertResponseIsSuccessful();

        $default = $this->defaultOf(self::CAP);
        $form = $crawler->selectButton('Save settings')->form();
        $form['settings['.self::CAP.']'] = '42';
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(42, $this->settings()->get(self::CAP));

        $logs = static::getContainer()->get(AdminActionLogRepository::class)
            ->findBy(['action' => SystemSettingsWriter::ACTION_CHANGE]);
        self::assertCount(1, $logs, 'exactly the one changed field is audited');
        self::assertSame(sprintf('%s: %d -> 42', self::CAP, $default), $logs[0]->getNote());
    }

    public function testResavingAnUntouchedFormWritesNothing(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $crawler = $client->request('GET', $this->url());

        $client->submit($crawler->selectButton('Save settings')->form());
        self::assertResponseRedirects();

        foreach (static::getContainer()->get(SettingsRegistry::class)->all() as $key => $_def) {
            self::assertFalse(
                $this->settings()->isOverridden($key),
                "{$key} must not be pinned by a save that changed nothing"
            );
        }
        self::assertCount(
            0,
            static::getContainer()->get(AdminActionLogRepository::class)
                ->findBy(['action' => SystemSettingsWriter::ACTION_CHANGE])
        );
    }

    public function testAnOutOfRangeValueSavesNothingAtAll(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $token = $this->tokenFrom($client);

        // A zero cap would freeze every region's queue. The 9 alongside it is
        // a perfectly legal ride threshold and must NOT be saved either: one
        // bad field rejects the whole submission, so the admin never ends up
        // with half of what they typed applied.
        $client->request('POST', $this->url(), [
            'token' => $token,
            'settings' => [
                self::CAP => '0',
                SettingsRegistry::ROUTE_RIDE_VERIFY_THRESHOLD => '9',
            ],
        ]);

        self::assertResponseIsSuccessful('the form is re-rendered with the error, not redirected');
        self::assertSelectorExists('.is-invalid');
        self::assertSame($this->defaultOf(self::CAP), $this->settings()->get(self::CAP));
        self::assertSame(
            $this->defaultOf(SettingsRegistry::ROUTE_RIDE_VERIFY_THRESHOLD),
            $this->settings()->get(SettingsRegistry::ROUTE_RIDE_VERIFY_THRESHOLD),
        );
    }

    public function testANonNumericValueIsRejectedRatherThanCastToZero(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $token = $this->tokenFrom($client);

        $client->request('POST', $this->url(), [
            'token' => $token,
            'settings' => [self::CAP => 'thirty'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame($this->defaultOf(self::CAP), $this->settings()->get(self::CAP));
    }

    public function testResetRestoresTheDefault(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true));
        static::getContainer()->get(SystemSettingsWriter::class)->set(self::CAP, 99, null);
        self::assertSame(99, $this->settings()->get(self::CAP));

        $token = $this->tokenFrom($client);
        $client->request('POST', $this->url(), ['token' => $token, 'reset' => self::CAP]);

        self::assertResponseRedirects();
        self::assertSame($this->defaultOf(self::CAP), $this->settings()->get(self::CAP));
        self::assertFalse($this->settings()->isOverridden(self::CAP));
    }

    public function testResettingAnUnknownKeyIs404RatherThanA500(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true));
        $token = $this->tokenFrom($client);

        $client->request('POST', $this->url(), ['token' => $token, 'reset' => 'coverage.csp_host']);

        self::assertResponseStatusCodeSame(404);
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    public function testAForgedTokenChangesNothing(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUser('admin@example.com', ['ROLE_ADMIN'], admin2fa: true));

        $client->request('POST', $this->url(), [
            'token' => 'forged',
            'settings' => [self::CAP => '7'],
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($this->defaultOf(self::CAP), $this->settings()->get(self::CAP));
    }
}
