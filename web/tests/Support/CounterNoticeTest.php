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
use App\Moderation\DeskSeen;
use App\Moderation\SeenSubject;
use App\Support\ContentReportService;
use App\Support\Entity\ContentReport;
use App\Support\ReportGround;
use App\Support\ReportStatus;
use App\Support\ReportTarget;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * The author's answer to an upheld copyright claim puts the report back on the
 * Reports desk (content-reports.md §7, §11).
 *
 * The answer page tells the author "we will tell you what was decided". So the
 * answer reopens the report: it is open work again, on the default Open view,
 * with the unseen bar for every curator, including the one who decided it the
 * first time. The next decision mails the claimant, as every decision does,
 * and the author, who was promised it.
 */
final class CounterNoticeTest extends WebTestCase
{
    private int $seq = 0;

    public function testTheAuthorsAnswerReopensTheReportAndTheNextDecisionMailsBothSides(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $owner = $this->rider('counter-owner@example.org');
        $curator = $this->rider('counter-curator@example.org', curator: true);
        $upload = $this->approved($owner);
        $report = $this->copyrightReport($upload);

        // First decision: upheld, the photo comes down, the author is told without being asked to.
        $client->loginUser($curator);
        $this->decide($client, $report, 'upheld', 'The original is on the claimant\'s own site, dated earlier.');
        $decided = $this->fresh($report);
        self::assertSame(ReportStatus::Upheld, $decided->getStatus());
        self::assertTrue($decided->isAuthorTold());
        self::assertSame([], $this->unseenFor($curator, $report), 'the deciding curator has opened it');

        // The author answers, signed in, through the page the statement links.
        $client->loginUser($owner);
        $page = $client->request('GET', '/report/'.$report->getId().'/answer');
        self::assertResponseIsSuccessful();
        $client->request('POST', '/report/'.$report->getId().'/answer', [
            '_token' => (string) $page->filter('input[name="_token"]')->attr('value'),
            'answer' => 'I took this photo myself on a ride in May; the claimant copied it from my upload.',
        ]);
        self::assertResponseIsSuccessful();

        $reopened = $this->fresh($report);
        self::assertTrue($reopened->hasCounterNotice());
        self::assertFalse($reopened->getStatus()->isDecided(), 'the answer puts the report back to waiting');
        self::assertSame([(string) $report->getId()], $this->unseenFor($curator, $report), 'it carries the unseen bar again, even for the curator who opened it before');

        // The author can still read what they sent.
        $client->request('GET', '/report/'.$report->getId().'/answer');
        self::assertResponseIsSuccessful();

        // It is on the desk's default Open view.
        $client->loginUser($curator);
        $list = $client->request('GET', '/moderate/reports');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $list->filter('.rrow.is-unseen a[href*="'.$report->getId().'"]'));

        // The next decision keeps the claim upheld (the photo is already gone)
        // and mails the claimant and the author.
        $this->decide($client, $report, 'upheld', 'We read both. The claimant\'s file is older and larger; the claim stands.');
        self::assertSame(ReportStatus::Upheld, $this->fresh($report)->getStatus());

        $to = [];
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            $to[] = implode(',', array_map(static fn (Address $a): string => $a->getAddress(), $message->getTo()));
        }
        sort($to);
        self::assertSame(['claimant@example.org', 'counter-owner@example.org'], $to);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function rider(string $email, bool $curator = false): User
    {
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $user->setDisplayName($curator ? 'Counter Desk Curator' : 'Counter Rider');
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

        $item = (new Item())->setLetter('A')->setName('Fontaine du contre-avis')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('counter-notice-'.++$this->seq)
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

    /** A copyright claim, filed through the service the public form calls. */
    private function copyrightReport(MediaUpload $upload): ContentReport
    {
        $report = static::getContainer()->get(ContentReportService::class)->file(
            ReportTarget::Photo,
            $upload->getId()->toRfc4122(),
            ReportGround::Copyright,
            'This is my photograph, published without my permission.',
            'claimant@example.org',
            null,
            '203.0.113.'.++$this->seq,
        );
        $report->setRightsClaim('https://example.org/original.jpg', 'Jane Claimant');
        $this->em()->flush();

        return $report;
    }

    private function decide(KernelBrowser $client, ContentReport $report, string $status, string $note): void
    {
        $page = $client->request('GET', '/moderate/reports/'.$report->getId());
        self::assertResponseIsSuccessful();
        $token = (string) $page->filter('form.decide input[name="_token"]')->attr('value');

        $client->request('POST', '/moderate/reports/'.$report->getId().'/decide', [
            '_token' => $token,
            'status' => $status,
            'note' => $note,
        ]);
        self::assertResponseRedirects();
    }

    private function fresh(ContentReport $report): ContentReport
    {
        $this->em()->clear();
        $row = $this->em()->find(ContentReport::class, $report->getId());
        self::assertInstanceOf(ContentReport::class, $row);

        return $row;
    }

    /** @return list<string> */
    private function unseenFor(User $curator, ContentReport $report): array
    {
        return array_map('strval', array_keys(static::getContainer()->get(DeskSeen::class)
            ->unseenAmong((int) $curator->getId(), SeenSubject::ContentReport, [(string) $report->getId()])));
    }
}
