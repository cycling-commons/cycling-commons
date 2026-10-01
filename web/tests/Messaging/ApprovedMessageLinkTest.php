<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Region;
use App\Catalog\Entity\Submission;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Catalog\SubmissionStatus;
use App\Catalog\SubmissionType;
use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\MessageService;
use App\Messaging\UserMessageKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * An approved submission's message links to where the submission landed:
 * its item on the map, a town's card on the map, or a region's page. The
 * message's own reference (`SUB-<id>`) names nothing the map can find, so the
 * link is read from the submission row, never from the message.
 *
 * Rows are built the way ModerationService::decide() leaves them: an approved
 * submission and a `submission_approved` message labelled `SUB-<id>`.
 *
 * @see docs/specs/moderation-and-contribution.md §7.7
 */
final class ApprovedMessageLinkTest extends WebTestCase
{
    private int $seq = 0;

    public function testAnApprovedPlaceLinksToItsItemOnTheMap(): void
    {
        $client = static::createClient();
        $rider = $this->user('item');
        $item = (new Item())->setLetter('A')->setName('Fontaine linked')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('approved-link-'.$this->seq)->setSource(ItemSource::User)->setState(ItemState::Verified)
            ->setAttributes([]);
        $this->em()->persist($item);
        $this->em()->flush();
        $sub = $this->submission($rider, SubmissionType::Edit, 'A', 'An old name', '{"type":"Point","coordinates":[5.86,50.47]}', [], (int) $item->getId());
        $m = $this->approvedMessage($rider, $sub);

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/account/messages');
        self::assertResponseIsSuccessful();

        self::assertSame('/map?item='.$item->getId().'&msg='.$m->getId(), $this->href($crawler, $m, 'a.msg-map-link'),
            'by id: the submission title need not be the item\'s name');

        // The contributions page links the same row to the same item.
        $crawler = $client->request('GET', '/account/contributions');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#sub-'.$sub->getId().' a.q-link[href="/map?item='.$item->getId().'"]'));
    }

    public function testAnApprovedTownTextLinksToTheTownCardOnTheMap(): void
    {
        $client = static::createClient();
        $rider = $this->user('town');
        $sub = $this->submission($rider, SubmissionType::Text, '', 'Spa', '{"type":"Point","coordinates":[5.8636,50.492]}', [
            'target' => 'town', 'ref' => 'relation/2422528', 'lang' => 'en', 'text' => 'Spa is a town.', 'details' => ['note' => ''],
        ]);
        $m = $this->approvedMessage($rider, $sub);

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/account/messages');
        self::assertResponseIsSuccessful();

        $href = $this->href($crawler, $m, 'a.msg-map-link');
        self::assertSame('/map?town=relation/2422528&ll=50.492000,5.863600&name=Spa&msg='.$m->getId(), $href);
        self::assertStringNotContainsString('SUB-', $href, 'the receipt label names no place');
        self::assertSame('View it on the map ↗', trim($crawler->filter('#msg-'.$m->getId().' a.msg-map-link')->text()));

        // Following it opens the message, like every map link a message carries.
        $client->request('GET', $href);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->reload($m)->isRead());
    }

    public function testAnApprovedRegionTextLinksToTheRegionPage(): void
    {
        $client = static::createClient();
        $rider = $this->user('region');
        $region = (new Region())->setSlug('msg-link-region')->setName('Msg Link Region')->setCountryCode('PN')->setAreaKm2(1.0)
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[-141,-41],[-140,-41],[-140,-40],[-141,-40],[-141,-41]]]]}');
        $this->em()->persist($region);
        $this->em()->flush();
        $sub = $this->submission($rider, SubmissionType::Text, '', 'Msg Link Region', '{"type":"Point","coordinates":[-140.5,-40.5]}', [
            // The payload's slug is what the region was called when the text was
            // proposed; the link follows the region's id to its slug now.
            'target' => 'region', 'ref' => (string) $region->getId(), 'slug' => 'an-old-slug', 'lang' => 'en', 'text' => 'A region.', 'derived' => false, 'details' => ['note' => ''],
        ]);
        $m = $this->approvedMessage($rider, $sub);

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/account/messages');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#msg-'.$m->getId().' a.msg-map-link'), 'a region page is not the map');
        $link = $crawler->filter('#msg-'.$m->getId().' a.msg-region-link');
        self::assertCount(1, $link);
        self::assertSame('/regions/msg-link-region', $link->attr('href'));
        self::assertSame('View the region page →', trim($link->text()));

        $client->request('GET', (string) $link->attr('href'));
        self::assertResponseIsSuccessful();
    }

    public function testAnApprovalWithNowhereToGoCarriesNoLink(): void
    {
        $client = static::createClient();
        $rider = $this->user('nowhere');
        // A new place approved with no item behind it (older rows hold these).
        $sub = $this->submission($rider, SubmissionType::NewItem, 'A', 'Zuiderdijk', '{"type":"Point","coordinates":[5.14,52.62]}', []);
        $m = $this->approvedMessage($rider, $sub);
        // A message whose submission is not the reader's own.
        $other = $this->user('other');
        $theirs = $this->submission($other, SubmissionType::Text, '', 'Spa', '{"type":"Point","coordinates":[5.8636,50.492]}', [
            'target' => 'town', 'ref' => 'relation/2422528', 'lang' => 'en', 'text' => 'Spa is a town.',
        ]);
        $stray = $this->svc()->sendSystem((int) $rider->getId(), UserMessageKind::SubmissionApproved, 'submission', (int) $theirs->getId(), 'SUB-'.$theirs->getId(), 'messages.body.submission_approved', ['%title%' => 'Spa']);
        self::assertInstanceOf(UserMessage::class, $stray);
        $this->em()->flush();

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/account/messages');
        self::assertResponseIsSuccessful();

        foreach ([$m, $stray] as $msg) {
            self::assertCount(1, $crawler->filter('#msg-'.$msg->getId()));
            self::assertCount(0, $crawler->filter('#msg-'.$msg->getId().' .q-map a'), 'no link rather than one that opens nothing');
        }
        self::assertStringNotContainsString('feature=SUB-', (string) $client->getResponse()->getContent());
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function svc(): MessageService
    {
        return static::getContainer()->get(MessageService::class);
    }

    private function user(string $name): User
    {
        $user = (new User())
            ->setEmail(sprintf('approved-link-%s-%d@example.test', $name, ++$this->seq))
            ->setDisplayName('Linked '.$name);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** @param array<string, mixed> $payload */
    private function submission(User $rider, SubmissionType $type, string $letter, string $title, string $geom, array $payload, ?int $itemId = null): Submission
    {
        $sub = (new Submission())->setType($type)->setLetter($letter)->setUserId((int) $rider->getId())
            ->setItemId($itemId)->setTitle($title)->setGeom($geom)->setCountryCode('BE')
            ->setChanges([])->setPayload($payload);
        $sub->setStatus(SubmissionStatus::Approved);
        $this->em()->persist($sub);
        $this->em()->flush();

        return $sub;
    }

    private function approvedMessage(User $rider, Submission $sub): UserMessage
    {
        $m = $this->svc()->sendSystem(
            (int) $rider->getId(),
            UserMessageKind::SubmissionApproved,
            'submission',
            (int) $sub->getId(),
            'SUB-'.$sub->getId(),
            'messages.body.submission_approved',
            ['%title%' => $sub->getTitle()],
        );
        self::assertInstanceOf(UserMessage::class, $m);
        $this->em()->flush();

        return $m;
    }

    private function href(Crawler $crawler, UserMessage $m, string $selector): string
    {
        $link = $crawler->filter('#msg-'.$m->getId().' '.$selector);
        self::assertCount(1, $link);

        return (string) $link->attr('href');
    }

    private function reload(UserMessage $m): UserMessage
    {
        $this->em()->clear();
        $found = $this->em()->find(UserMessage::class, $m->getId());
        self::assertInstanceOf(UserMessage::class, $found);

        return $found;
    }
}
