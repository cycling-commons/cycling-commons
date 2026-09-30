<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Messaging;

use App\Catalog\Entity\Item;
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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A message counts as read when its reader OPENS it, never because a page
 * listing it loaded: the reader expands it on the messages page, or follows
 * the message's own link to the thing it is about. Each one opened lowers the
 * unread count by exactly one.
 *
 * @see docs/specs/moderation-and-contribution.md §7.5a
 */
final class MessageOpenedTest extends WebTestCase
{
    private int $seq = 0;

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
            ->setEmail(sprintf('opened-%s-%d@example.test', $name, ++$this->seq))
            ->setDisplayName('Opened '.$name);
        $user->setEmailVerified(true);
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setRoles([]);
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function approved(User $rider, string $title): UserMessage
    {
        $m = $this->svc()->sendSystem(
            (int) $rider->getId(),
            UserMessageKind::SubmissionApproved,
            'submission',
            100 + $this->seq,
            $title,
            'messages.body.submission_approved',
            ['%title%' => $title],
        );
        self::assertInstanceOf(UserMessage::class, $m);
        $this->em()->flush();

        return $m;
    }

    private function token(Crawler $crawler): string
    {
        $list = $crawler->filter('[data-read-token]');
        self::assertCount(1, $list, 'the list carries the token its open requests send');

        return (string) $list->attr('data-read-token');
    }

    private function open(KernelBrowser $client, int $id, string $token): void
    {
        $client->request('POST', '/account/messages/'.$id.'/read', ['_token' => $token], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    public function testLoadingTheMessagesPageMarksNothingRead(): void
    {
        $client = static::createClient();
        $rider = $this->user('loader');
        $a = $this->approved($rider, 'Fountain A');
        $b = $this->approved($rider, 'Fountain B');
        self::assertSame(2, $this->svc()->unreadCount((int) $rider->getId()));

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/account/messages');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.msg-row.msg-new'));
        // Each unread row wears the one unseen bar, with its words for a screen reader.
        self::assertCount(2, $crawler->filter('.msg-row.is-unseen'));
        self::assertSame('Not opened yet', trim($crawler->filter('#msg-'.$a->getId().' .unseen-note')->text()));
        // Each unread row is closed until it is opened, and says where to report opening it.
        self::assertCount(2, $crawler->filter('.msg-row[data-read-url] details.msg-open:not([open])'));
        self::assertSame('/account/messages/'.$a->getId().'/read', $crawler->filter('#msg-'.$a->getId())->attr('data-read-url'));

        $client->request('GET', '/account/messages');
        $client->request('GET', '/account/messages?unread=1');
        self::assertSame(2, $this->svc()->unreadCount((int) $rider->getId()), 'a list page marks nothing, however often it loads');
        self::assertFalse($this->reload($b)->isRead());
    }

    public function testOpeningOneMessageLowersTheBadgeByOne(): void
    {
        $client = static::createClient();
        $rider = $this->user('opener');
        $a = $this->approved($rider, 'Fountain C');
        $this->approved($rider, 'Fountain D');
        $this->approved($rider, 'Fountain E');

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/account/messages');
        self::assertSame('3', trim($crawler->filter('.acct-dropdown a[href$="/account/messages"] .acct-count')->text()));
        $token = $this->token($crawler);

        $this->open($client, (int) $a->getId(), $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['read' => true, 'unread' => 2], json_decode((string) $client->getResponse()->getContent(), true));
        self::assertSame(2, $this->svc()->unreadCount((int) $rider->getId()));

        // Opening it again changes nothing: one message, one step down.
        $this->open($client, (int) $a->getId(), $token);
        self::assertSame(['read' => false, 'unread' => 2], json_decode((string) $client->getResponse()->getContent(), true));

        $crawler = $client->request('GET', '/account/messages');
        self::assertSame('2', trim($crawler->filter('.acct-dropdown a[href$="/account/messages"] .acct-count')->text()));
        self::assertCount(2, $crawler->filter('.msg-row.msg-new'));
        self::assertCount(0, $crawler->filter('#msg-'.$a->getId().'.msg-new'));
        self::assertCount(0, $crawler->filter('#msg-'.$a->getId().'.is-unseen'), 'opened: the bar is gone');
    }

    public function testAnOpenWithoutAValidTokenIsRefused(): void
    {
        $client = static::createClient();
        $rider = $this->user('tokenless');
        $a = $this->approved($rider, 'Fountain F');
        $client->loginUser($rider);

        $this->open($client, (int) $a->getId(), 'not-a-token');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->svc()->unreadCount((int) $rider->getId()));
    }

    public function testArrivingThroughAMessageLinkMarksThatMessageAndNoOther(): void
    {
        $client = static::createClient();
        $rider = $this->user('arriver');
        $a = $this->approved($rider, 'Fountain G');
        $b = $this->approved($rider, 'Fountain H');

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/account/messages');
        $link = $crawler->filter('#msg-'.$a->getId().' a.msg-map-link');
        self::assertCount(1, $link);
        $href = (string) $link->attr('href');
        self::assertStringContainsString('msg='.$a->getId(), $href, 'the map link names the message it comes from');

        $client->request('GET', $href);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->reload($a)->isRead());
        self::assertFalse($this->reload($b)->isRead());
        self::assertSame(1, $this->svc()->unreadCount((int) $rider->getId()));
    }

    public function testANeedsInfoQuestionOpensInTheEditFormAndThatMarksIt(): void
    {
        $client = static::createClient();
        $rider = $this->user('asked');
        $curator = $this->user('asking');

        $item = (new Item())->setLetter('A')->setName('Fontaine asked '.$this->seq)
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setSourceRef('opened-'.$this->seq)->setSource(ItemSource::User)->setState(ItemState::Verified)
            ->setAttributes([]);
        $this->em()->persist($item);
        $this->em()->flush();
        $sub = (new Submission())->setType(SubmissionType::Edit)->setLetter('A')->setUserId((int) $rider->getId())
            ->setItemId((int) $item->getId())->setTitle('Fontaine asked')
            ->setGeom('{"type":"Point","coordinates":[5.86,50.47]}')->setCountryCode('BE')
            ->setChanges([])->setPayload([]);
        $sub->setStatus(SubmissionStatus::NeedsInfo);
        $sub->setDecidedBy((int) $curator->getId());
        $this->em()->persist($sub);
        $this->em()->flush();

        $q = $this->svc()->sendSystem(
            (int) $rider->getId(),
            UserMessageKind::SubmissionNeedsInfo,
            'submission',
            (int) $sub->getId(),
            'SUB-'.$sub->getId(),
            'messages.body.submission_needs_info',
            ['%title%' => 'Fontaine asked'],
            'Is the tap still there?',
        );
        self::assertInstanceOf(UserMessage::class, $q);
        $other = $this->approved($rider, 'Fountain I');

        $client->loginUser($rider);
        $crawler = $client->request('GET', '/account/messages');
        $link = $crawler->filter('#msg-'.$q->getId().' a.msg-edit-link');
        self::assertCount(1, $link, 'a needs-info question links to the edit form of what it asks about');
        $href = (string) $link->attr('href');
        self::assertStringContainsString('item='.$item->getId(), $href);
        self::assertStringContainsString('msg='.$q->getId(), $href);

        $client->request('GET', $href);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->reload($q)->isRead());
        self::assertFalse($this->reload($other)->isRead());
    }

    public function testAnotherUsersMessageIdIsRefused(): void
    {
        $client = static::createClient();
        $owner = $this->user('owner');
        $intruder = $this->user('intruder');
        $theirs = $this->approved($owner, 'Fountain J');
        $mine = $this->approved($intruder, 'Fountain K');

        $client->loginUser($intruder);
        $crawler = $client->request('GET', '/account/messages');
        $token = $this->token($crawler);

        $this->open($client, (int) $theirs->getId(), $token);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/map?msg='.$theirs->getId());
        self::assertResponseIsSuccessful();

        self::assertFalse($this->reload($theirs)->isRead(), 'nobody opens a message addressed to somebody else');
        self::assertSame(1, $this->svc()->unreadCount((int) $owner->getId()));
        self::assertFalse($this->reload($mine)->isRead());
    }

    private function reload(UserMessage $m): UserMessage
    {
        $this->em()->clear();
        $found = $this->em()->find(UserMessage::class, $m->getId());
        self::assertInstanceOf(UserMessage::class, $found);

        return $found;
    }
}
