<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Media\Entity\ConsentRecord;
use App\Media\Entity\MediaUpload;
use App\Media\MediaConsent;
use App\Media\MediaDecisionService;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use App\Support\ContentReportService;
use App\Support\Entity\ContentReport;
use App\Support\ReportGround;
use App\Support\ReportStatus;
use App\Support\ReportTarget;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * What a decision on the reports desk does to the photo it is about
 * (docs/specs/content-reports.md §9).
 *
 * Every photo report raises a pending takedown, and the desk is the only place
 * that pending takedown is ever decided. So the three outcomes have to leave the
 * photo in a state that is true and that somebody can still act on:
 *
 * * **Moot cannot close a report while a takedown waits on the photo.** It
 *   would leave the photo hidden, on no desk, and blocking every later report.
 */
final class PhotoReportDecisionTest extends WebTestCase
{
    private int $seq = 0;

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

    private function rider(string $email, bool $curator = false): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName($curator ? 'Photo Desk Curator' : 'Photo Rider');
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        if ($curator) {
            $user->setRoles(['ROLE_CURATOR']);
            // Elevated roles must be TOTP-enrolled or every curator page goes
            // to /2fa/setup (docs/specs/account-and-auth.md).
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** An approved photo, stored and attached to an item, exactly as approval leaves it. */
    private function approved(User $owner): MediaUpload
    {
        $em = $this->em();
        $consent = new ConsentRecord(Uuid::v4(), (int) $owner->getId(), MediaConsent::KIND, MediaConsent::VERSION, MediaConsent::hash('x'));
        $em->persist($consent);

        $item = (new Item())->setLetter('A')->setName('Fontaine du signalement')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('photo-report-decision-'.++$this->seq)
            ->setSource(ItemSource::User)->setState(ItemState::Verified);
        $em->persist($item);
        $em->flush();

        $upload = new MediaUpload(Uuid::v4(), (int) $owner->getId(), $consent->getId(), 'EU', 1200, 900, 4242, bucket: 'test-bucket-eu-01');
        $em->persist($upload);
        $upload->approve($item->getId());
        $em->flush();

        static::getContainer()->get(MediaStorage::class)
            ->store('test-bucket-eu-01', $upload->getPathPrefix(), new ProcessedPhoto('O', 'L', 'S', 1200, 900));
        $item->setAttributes(['photos' => [static::getContainer()->get(MediaDecisionService::class)->describe($upload)]]);
        $em->flush();

        return $upload;
    }

    /** File a report through the same service the public form calls. */
    private function report(string $photoId, ReportGround $ground, ?string $contact = 'reporter@example.org'): ContentReport
    {
        return static::getContainer()->get(ContentReportService::class)->file(
            ReportTarget::Photo,
            $photoId,
            $ground,
            'This picture should not be on the site.',
            $contact,
            null,
            '203.0.113.'.++$this->seq,
        );
    }

    private function fresh(ContentReport $report): ContentReport
    {
        $this->em()->clear();
        $row = $this->em()->find(ContentReport::class, $report->getId());
        self::assertInstanceOf(ContentReport::class, $row);

        return $row;
    }

    private function photo(MediaUpload $upload): MediaUpload
    {
        $this->em()->clear();
        $row = $this->em()->find(MediaUpload::class, $upload->getId());
        self::assertInstanceOf(MediaUpload::class, $row);

        return $row;
    }

    private function decide(KernelBrowser $client, ContentReport $report, string $status, string $note = 'Looked at it closely.'): void
    {
        $page = $client->request('GET', '/moderate/reports/'.$report->getId());
        self::assertResponseIsSuccessful();
        $token = (string) $page->filter('form.decide input[name="_token"]')->attr('value');

        $client->request('POST', '/moderate/reports/'.$report->getId().'/decide', [
            '_token' => $token,
            'status' => $status,
            'note' => $note,
        ]);
    }

    // -- Moot (BUG-01) -------------------------------------------------------

    public function testMootIsRefusedWhileATakedownWaitsOnThePhoto(): void
    {
        $client = $this->client();
        $upload = $this->approved($this->rider('moot-owner@example.org'));
        $report = $this->report($upload->getId()->toRfc4122(), ReportGround::IntimateOrChild, null);
        self::assertTrue($this->photo($upload)->isTakedownWithheld(), 'the urgent ground hid it on the spot');

        $client->loginUser($this->rider('moot-curator@example.org', curator: true));
        $page = $client->request('GET', '/moderate/reports/'.$report->getId());
        self::assertSame(0, $page->filter('#d-status option[value="moot"]')->count(), 'Moot is not offered while a takedown waits');

        $this->decide($client, $report, 'moot');
        self::assertEmailCount(0, null, 'a refused decision tells nobody anything');
        $client->followRedirect();
        self::assertSelectorTextContains('#d-status-error', 'Upheld');

        self::assertSame(ReportStatus::Open, $this->fresh($report)->getStatus());
        self::assertTrue($this->photo($upload)->isTakedownPending(), 'still waiting for a real decision');
    }

    public function testMootStaysAvailableWhenNothingWaitsOnThePhoto(): void
    {
        $client = $this->client();
        // A uuid that names no photo: already gone, the case Moot is for.
        $report = $this->report(Uuid::v4()->toRfc4122(), ReportGround::PrivateProperty);

        $client->loginUser($this->rider('moot-gone-curator@example.org', curator: true));
        $page = $client->request('GET', '/moderate/reports/'.$report->getId());
        self::assertSame(1, $page->filter('#d-status option[value="moot"]')->count());

        $this->decide($client, $report, 'moot', 'The photo is no longer on the site.');
        self::assertSame(ReportStatus::Moot, $this->fresh($report)->getStatus());
    }
}
