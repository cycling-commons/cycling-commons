<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Media\AlertRecipients;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * With no alert recipient, an escalation, an opened circuit breaker and a
 * server error reach nobody but the log. The admin dashboard says so in red
 * until an address is set.
 *
 * @see docs/specs/system-configuration.md §2
 */
final class AlertRecipientNoticeTest extends WebTestCase
{
    private const string NOTICE = 'Nobody receives security alerts.';

    private function adminClient(string $alertEmails): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $settings = new class($alertEmails) implements SettingsProviderInterface {
            public function __construct(private readonly string $alertEmails)
            {
            }

            #[\Override]
            public function get(string $key): int
            {
                throw new \InvalidArgumentException($key);
            }

            #[\Override]
            public function getString(string $key): string
            {
                return SettingsRegistry::ALERT_EMAILS === $key ? $this->alertEmails : '';
            }
        };
        static::getContainer()->set(AlertRecipients::class, new AlertRecipients($settings));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $admin = (new User())->setEmail('alert-notice-admin@example.test');
        $admin->setPassword('x');
        $admin->setDisplayName('Alert Notice Admin');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setTotpSecret('JBSWY3DPEHPK3PXP');
        $admin->setTwoFaEnabled(true);
        $em->persist($admin);
        $em->flush();
        $client->loginUser($admin);

        return $client;
    }

    public function testTheDashboardSaysInRedWhenNobodyGetsAlerts(): void
    {
        $client = $this->adminClient('');

        $page = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        $notice = $page->filter('.alert-danger[role="alert"]');
        self::assertCount(1, $notice);
        self::assertStringContainsString(self::NOTICE, $notice->text());
        self::assertCount(1, $notice->filter('a[href$="/admin/system-config"]'));
    }

    public function testAConfiguredRecipientShowsNoNotice(): void
    {
        $client = $this->adminClient('ops@example.test');

        $page = $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::NOTICE, $page->text());
    }
}
