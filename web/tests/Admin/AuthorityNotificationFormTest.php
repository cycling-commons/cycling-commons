<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaEscalationService;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use App\Moderation\AuthorityNotifications;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The admin-only form on /admin/escalated that records a DSA Art. 18
 * notification, and the overdue warning on that page and on the dashboard
 * (docs/specs/operations.md §7).
 */
final class AuthorityNotificationFormTest extends WebTestCase
{
    /** @param list<string> $roles */
    private function user(string $name, array $roles = []): User
    {
        $u = (new User())->setEmail($name.'-'.uniqid().'@example.com');
        $u->setDisplayName($name);
        $u->setPassword('x');
        $u->setRoles($roles);
        if (\in_array('ROLE_ADMIN', $roles, true)) {
            $u->setTotpSecret('JBSWY3DPEHPK3PXP');
            $u->setTwoFaEnabled(true);
        }
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($u);
        $em->flush();

        return $u;
    }

    private function heldPhoto(int $hoursAgo = 0): MediaUpload
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $owner = $this->user('art18-owner');
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);
        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 900, 600, 4242, bucket: 'test-bucket-eu-01');
        $em->persist($upload);
        $em->flush();
        static::getContainer()->get(MediaStorage::class)
            ->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 900, 600));
        static::getContainer()->get(MediaEscalationService::class)
            ->escalate($upload, $this->user('art18-curator'), 'A threat against a named person.');
        if ($hoursAgo > 0) {
            static::getContainer()->get(Connection::class)->executeStatement(
                'UPDATE media_upload SET escalated_at = NOW() - make_interval(hours => :h) WHERE id = :id',
                ['h' => $hoursAgo, 'id' => $upload->getId()->toRfc4122()],
            );
        }

        return $upload;
    }

    private function admin(KernelBrowser $client): User
    {
        $admin = $this->user('art18-admin', ['ROLE_ADMIN']);
        $client->loginUser($admin);

        return $admin;
    }

    public function testAnOverdueHoldIsFlaggedOnTheDashboardAndTheHeldList(): void
    {
        $client = static::createClient();
        $upload = $this->heldPhoto(AuthorityNotifications::OVERDUE_HOURS + 2);
        $this->admin($client);

        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-art18-overdue]');

        $crawler = $client->request('GET', '/admin/escalated');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-art18-overdue-banner]');
        self::assertCount(1, $crawler->filter(\sprintf('[data-held="%s"][data-art18-state="overdue"]', $upload->getId()->toRfc4122())));
    }

    public function testAnAdminRecordsTheNotificationAndThePageShowsIt(): void
    {
        $client = static::createClient();
        $upload = $this->heldPhoto(AuthorityNotifications::OVERDUE_HOURS + 2);
        $admin = $this->admin($client);
        $uuid = $upload->getId()->toRfc4122();

        $crawler = $client->request('GET', '/admin/escalated');
        $form = $crawler->filter(\sprintf('form[data-art18-form="%s"]', $uuid))->form();
        $form['authority'] = 'Politie (NL)';
        $form['reference'] = 'PL-2026-42';
        $client->submit($form);
        self::assertResponseRedirects();

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $row = static::getContainer()->get(EntityManagerInterface::class)->find(MediaUpload::class, $upload->getId());
        self::assertNotNull($row);
        self::assertSame('Politie (NL)', $row->getAuthorityName());
        self::assertSame('PL-2026-42', $row->getAuthorityReference());
        self::assertSame($admin->getId(), $row->getAuthorityNotifiedById());

        $crawler = $client->request('GET', '/admin/escalated');
        $card = $crawler->filter(\sprintf('[data-held="%s"]', $uuid));
        self::assertSame('notified', $card->attr('data-art18-state'));
        self::assertStringContainsString('Politie (NL)', $card->text());
        self::assertStringContainsString('PL-2026-42', $card->text());
        self::assertCount(0, $crawler->filter(\sprintf('form[data-art18-form="%s"]', $uuid)), 'the record is written once');
    }

    public function testARefusedRecordSaysWhy(): void
    {
        $client = static::createClient();
        $upload = $this->heldPhoto();
        $this->admin($client);

        $client->request('POST', '/admin/escalated', [
            '_token' => 'ignored-in-test',
            'action' => 'notify',
            'media' => $upload->getId()->toRfc4122(),
            'authority' => '',
        ]);
        // Without a valid token the request goes no further.
        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/admin/escalated');
        $form = $crawler->filter(\sprintf('form[data-art18-form="%s"]', $upload->getId()->toRfc4122()))->form();
        $form['authority'] = '   ';
        $client->submit($form);
        $client->followRedirect();
        self::assertSelectorExists('.alert-danger');
        self::assertNull(static::getContainer()->get(Connection::class)->fetchOne(
            'SELECT authority_notified_at FROM media_upload WHERE id = :id', ['id' => $upload->getId()->toRfc4122()],
        ));
    }

    public function testACuratorCannotRecordOne(): void
    {
        $client = static::createClient();
        $upload = $this->heldPhoto();
        $client->loginUser($this->user('art18-curator-2', ['ROLE_CURATOR']));

        $client->request('POST', '/admin/escalated', [
            '_token' => 'x',
            'action' => 'notify',
            'media' => $upload->getId()->toRfc4122(),
            'authority' => 'Politie',
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
