<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Roadmap;

use App\Entity\User;
use App\Messaging\Entity\UserMessage;
use App\Messaging\UserMessageKind;
use App\Roadmap\Entity\RoadmapItem;
use App\Roadmap\Entity\RoadmapProposal;
use App\Roadmap\Entity\RoadmapProposalImage;
use App\Roadmap\ItemDesk;
use App\Roadmap\ItemState;
use App\Roadmap\ProposalIntake;
use App\Roadmap\ProposalStatus;
use App\Roadmap\RoadmapDeletionHook;
use App\Roadmap\RoadmapRefused;
use App\Settings\SettingsRegistry;
use App\Settings\SystemSettingsWriter;
use App\Support\StoredImage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The roadmap in the database and the rider's say in it
 * (docs/specs/roadmap-and-changelog.md §8): items, votes, follows,
 * proposals, the roadmap desk and the messages they send.
 *
 * The first test is the leak test: a proposal that is waiting, declined or
 * promoted never shows on the Proposals tab, and nothing but a live item or
 * an open proposal takes a vote or a follow.
 */
final class RoadmapSayTest extends WebTestCase
{
    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->say(true);
    }

    public function testOnlyAnOpenProposalIsShownAndOnlyLiveThingsAreMarkable(): void
    {
        $author = $this->rider('author');
        $open = $this->proposal($author, 'Open proposal title', ProposalStatus::Open);
        $hidden = [
            $this->proposal($author, 'Waiting proposal title', ProposalStatus::Pending),
            $this->proposal($author, 'Declined proposal title', ProposalStatus::Declined),
        ];
        $closed = $this->item('later', ['en' => ['Shipped item title', '']]);
        $this->desk()->close($closed, ItemState::Shipped);

        foreach ([null, $this->rider('other')] as $viewer) {
            if (null !== $viewer) {
                $this->client->loginUser($viewer);
            }
            foreach (['/roadmap', '/roadmap?tab=proposals'] as $url) {
                $page = $this->client->request('GET', $url);
                self::assertResponseIsSuccessful();
                $html = (string) $this->client->getResponse()->getContent();
                foreach ($hidden as $proposal) {
                    self::assertStringNotContainsString($proposal->getTitle(), $html);
                }
                // A shipped item leaves the list (§8.2a): it may stand in Latest additions beside it, never in it.
                self::assertStringNotContainsString('Shipped item title', $page->filter('.rwrap')->html());
            }
            self::assertCount(1, $page->filter('#idea-'.$open->getId()));
        }

        $token = $this->markToken('/roadmap?tab=proposals');
        foreach ([...array_map(static fn (RoadmapProposal $p): string => $p->getRef(), $hidden), $closed->getRef(), 'item:999999', 'roadmap.item_heatmap'] as $ref) {
            foreach (['vote', 'follow'] as $do) {
                $this->client->request('POST', '/roadmap/mark', ['_token' => $token, 'ref' => $ref, 'do' => $do], [], ['HTTP_ACCEPT' => 'application/json']);
                self::assertResponseStatusCodeSame(404);
            }
        }
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM roadmap_vote'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM roadmap_follow'));
    }

    public function testWithTheSayOffTheRoadmapIsAListOnly(): void
    {
        $this->say(false);
        $item = $this->item('next', ['en' => ['A switched off item', 'Still listed.']]);
        $this->client->loginUser($this->rider('switched-off'));

        $page = $this->client->request('GET', '/roadmap');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('A switched off item', $page->filter('#item-'.$item->getId())->text());
        self::assertCount(0, $page->filter('form[data-roadmap-mark]'));
        self::assertCount(0, $page->filter('.rtabs'));

        $this->client->request('GET', '/roadmap?tab=proposals');
        self::assertCount(1, $this->client->getCrawler()->filter('#item-'.$item->getId()));
        $this->client->request('GET', '/roadmap/propose');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/roadmap/mark', ['ref' => $item->getRef(), 'do' => 'vote']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAVisitorSeesCountsAndSignInLinksButNoForm(): void
    {
        $item = $this->item('later', ['en' => ['Counted item', '']]);
        $this->db()->executeStatement('INSERT INTO roadmap_vote (user_id, item_ref, created_at) VALUES (:u, :r, NOW())', ['u' => (int) $this->rider('voter')->getId(), 'r' => $item->getRef()]);

        $page = $this->client->request('GET', '/roadmap');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('form[data-roadmap-mark]'));
        self::assertCount(0, $page->filter('input[name="_token"]'));
        $row = $page->filter('#item-'.$item->getId());
        self::assertSame('1', trim($row->filter('.rcount')->text()));
        self::assertStringContainsString('/login', (string) $row->filter('a.rvote')->attr('href'));
    }

    public function testAnItemReadsInThePageLanguageOrFallsBackToEnglish(): void
    {
        $item = $this->item('now', ['en' => ['Water on the profile', 'English words.'], 'nl' => ['Water in het profiel', 'Nederlandse woorden.']]);

        $nl = $this->client->request('GET', '/nl/routekaart')->filter('#item-'.$item->getId());
        self::assertStringContainsString('Water in het profiel', $nl->text());
        self::assertSame(0, $nl->filter('p[lang]')->count());

        $fr = $this->client->request('GET', '/fr/feuille-de-route')->filter('#item-'.$item->getId());
        self::assertStringContainsString('Water on the profile', $fr->text());
        self::assertSame('en', $fr->filter('p')->attr('lang'));
    }

    public function testAVoteAndAFollowAreTogglesThatCountOnce(): void
    {
        $item = $this->item('later', ['en' => ['Toggle item', '']]);
        $this->client->loginUser($this->rider('toggler'));
        $token = $this->markToken('/roadmap');

        self::assertSame(['on' => true, 'votes' => 1], $this->postMark($token, $item->getRef(), 'vote'));
        self::assertSame(['on' => false, 'votes' => 0], $this->postMark($token, $item->getRef(), 'vote'));
        self::assertSame(['on' => true, 'votes' => 1], $this->postMark($token, $item->getRef(), 'vote'));
        self::assertTrue($this->postMark($token, $item->getRef(), 'follow')['on']);

        // Without script: a plain form post lands back on the item.
        $this->client->request('POST', '/roadmap/mark', ['_token' => $token, 'ref' => $item->getRef(), 'do' => 'follow']);
        self::assertResponseRedirects('/roadmap#item-'.$item->getId(), 303);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM roadmap_follow'));

        $row = $this->client->request('GET', '/roadmap')->filter('#item-'.$item->getId());
        self::assertSame('true', $row->filter('button.rvote')->attr('aria-pressed'));
        self::assertSame('false', $row->filter('button.rfollow')->attr('aria-pressed'));
    }

    public function testAnUnconfirmedRiderCannotVoteAndABadTokenIsRefused(): void
    {
        $item = $this->item('later', ['en' => ['Guarded item', '']]);
        $this->client->loginUser($this->rider('unconfirmed', [], false));
        $token = $this->markToken('/roadmap');
        $this->client->request('POST', '/roadmap/mark', ['_token' => $token, 'ref' => $item->getRef(), 'do' => 'vote'], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/roadmap/mark', ['_token' => 'nope', 'ref' => $item->getRef(), 'do' => 'vote']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM roadmap_vote'));
    }

    public function testARiderSendsAProposalWithAPictureAndSeesItWaiting(): void
    {
        $rider = $this->rider('proposer');
        $this->client->loginUser($rider);
        $page = $this->client->request('GET', '/roadmap/propose');
        self::assertResponseIsSuccessful();
        $token = (string) $page->filter('form.rprop-form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/roadmap/propose', ['_token' => $token, 'title' => 'x', 'problem' => 'A long enough problem.', 'idea' => 'A long enough idea.']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('A long enough problem.', (string) $this->client->getResponse()->getContent());

        $png = tempnam(sys_get_temp_dir(), 'idea');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));
        $this->client->request('POST', '/roadmap/propose', [
            '_token' => $token,
            'title' => 'Water on the route profile',
            'problem' => 'I cannot see where to refill on a long ride.',
            'idea' => 'Show taps along the elevation profile.',
            'benefit' => '',
        ], ['image' => new UploadedFile($png, 'sketch.png', 'image/png', null, true)]);
        self::assertResponseStatusCodeSame(303);

        $proposal = $this->em()->getRepository(RoadmapProposal::class)->findOneBy(['title' => 'Water on the route profile']);
        self::assertInstanceOf(RoadmapProposal::class, $proposal);
        self::assertSame(ProposalStatus::Pending, $proposal->getStatus());
        self::assertNull($proposal->getBenefit());
        $image = $this->em()->getRepository(RoadmapProposalImage::class)->findOneBy(['proposal' => $proposal]);
        self::assertInstanceOf(RoadmapProposalImage::class, $image);
        self::assertTrue($image->isPending());

        self::assertCount(1, $this->client->request('GET', '/roadmap/propose')->filter('#idea-'.$proposal->getId()));
        $this->client->request('GET', '/roadmap/ideas/'.$proposal->getId().'/image');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAReadyPictureReachesItsRiderAndTheDeskOnly(): void
    {
        $rider = $this->rider('picture-owner');
        $proposal = $this->proposal($rider, 'Proposal with a picture', ProposalStatus::Open);
        $image = RoadmapProposalImage::pending($proposal, 'raw bytes');
        $image->markReady(new StoredImage('image/webp', 'webp bytes', 4, 3));
        $this->em()->persist($image);
        $this->em()->flush();
        $path = '/roadmap/ideas/'.$proposal->getId().'/image';

        $this->client->loginUser($rider);
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame('webp bytes', $this->client->getResponse()->getContent());
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->client->loginUser($this->rider('picture-stranger'));
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
        // An open proposal is public, its picture is not.
        $this->client->request('GET', '/roadmap?tab=proposals');
        self::assertStringNotContainsString('/image', $this->client->getCrawler()->filter('#idea-'.$proposal->getId())->html());

        // Curators read proposals, pictures included.
        $this->client->loginUser($this->rider('picture-curator', ['ROLE_CURATOR']));
        $this->client->request('GET', '/moderate/ideas/'.$proposal->getId().'/image');
        self::assertResponseIsSuccessful();
        // Without the rider's permission there is no public address for it.
        $this->client->request('GET', '/roadmap/pictures/'.$proposal->getId());
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($this->rider('picture-admin', ['ROLE_ADMIN']));
        $this->client->request('GET', '/moderate/ideas/'.$proposal->getId().'/image');
        self::assertResponseIsSuccessful();
    }

    public function testCuratorsReadProposalsAndOnlyAdminsDecide(): void
    {
        $proposal = $this->proposal($this->rider('desk-author'), 'Read by curators', ProposalStatus::Pending);
        $this->client->loginUser($this->rider('curator', ['ROLE_CURATOR']));
        foreach (['/moderate/roadmap', '/moderate/roadmap/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
        foreach (['/moderate/ideas', '/moderate/ideas/'.$proposal->getId()] as $url) {
            $page = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $page->filter('form.decide'));
        }
        $this->client->request('POST', '/moderate/ideas/'.$proposal->getId().'/decide', ['action' => 'open']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(ProposalStatus::Pending, $this->reloadProposal($proposal)->getStatus());

        $this->client->loginUser($this->rider('admin', ['ROLE_ADMIN']));
        foreach (['/moderate/roadmap', '/moderate/roadmap/new', '/moderate/ideas'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
        }
    }

    public function testAProposalIsOpenedVotedOnAndPromotedWithItsMarks(): void
    {
        $author = $this->rider('promo-author');
        $voter = $this->rider('promo-voter');
        $proposal = $this->proposal($author, 'Night riding layer', ProposalStatus::Pending, 'nl');
        $this->client->loginUser($this->rider('promo-admin', ['ROLE_ADMIN']));

        $this->decideProposal($proposal, ['action' => 'open', 'public_title' => 'Lit roads at night', 'note' => 'Open for votes.']);
        self::assertSame(ProposalStatus::Open, $this->reloadProposal($proposal)->getStatus());
        self::assertSame(['messages.body.roadmap_idea_open'], $this->bodies($author, UserMessageKind::RoadmapIdeaOutcome));
        $this->client->request('GET', '/roadmap?tab=proposals');
        self::assertStringContainsString('Lit roads at night', $this->client->getCrawler()->filter('#idea-'.$proposal->getId())->text());

        // A rider votes for it and follows it; the author follows it already.
        $this->db()->executeStatement('INSERT INTO roadmap_vote (user_id, item_ref, created_at) VALUES (:u, :r, NOW())', ['u' => $voter->getId(), 'r' => $proposal->getRef()]);
        $this->db()->executeStatement('INSERT INTO roadmap_follow (user_id, item_ref, created_at) VALUES (:u, :r, NOW())', ['u' => $voter->getId(), 'r' => $proposal->getRef()]);

        // Rewording an open proposal takes no note.
        $this->decideProposal($proposal, ['action' => 'open', 'public_title' => 'Lit roads', 'note' => 'Nobody reads this.'], 422);

        $this->decideProposal($proposal, ['action' => 'promote', 'group' => 'next', 'note' => 'Going up.'], 303);
        $proposal = $this->reloadProposal($proposal);
        self::assertSame(ProposalStatus::Promoted, $proposal->getStatus());
        $item = $proposal->getPromotedItem();
        self::assertInstanceOf(RoadmapItem::class, $item);
        self::assertSame('next', $item->getStatus());
        self::assertSame('Lit roads at night', $item->textIn('nl')?->getTitle());
        self::assertSame($proposal, $item->getSourceProposal());
        self::assertResponseRedirects('/moderate/roadmap/'.$item->getId(), 303);

        // The marks moved: one vote on the item, two followers, none left on the proposal.
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM roadmap_vote WHERE item_ref = :r', ['r' => $item->getRef()]));
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM roadmap_follow WHERE item_ref = :r', ['r' => $item->getRef()]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM roadmap_follow WHERE item_ref = :r', ['r' => $proposal->getRef()]));

        self::assertSame(['messages.body.roadmap_idea_open', 'messages.body.roadmap_idea_promoted_next'], $this->bodies($author, UserMessageKind::RoadmapIdeaOutcome));
        self::assertSame([], $this->bodies($author, UserMessageKind::RoadmapItemMoved));
        self::assertSame(['messages.body.roadmap_moved_next'], $this->bodies($voter, UserMessageKind::RoadmapItemMoved));

        $this->client->request('GET', '/roadmap?tab=proposals');
        self::assertCount(0, $this->client->getCrawler()->filter('#idea-'.$proposal->getId()));
        $row = $this->client->request('GET', '/nl/routekaart')->filter('#item-'.$item->getId());
        self::assertStringContainsString('Lit roads at night', $row->text());
        self::assertCount(1, $row->filter('.ridea'));

        // Promoted is final on the proposal.
        $token = $this->deskToken('/moderate/ideas/'.$this->proposal($author, 'Other', ProposalStatus::Pending)->getId());
        $this->client->request('POST', '/moderate/ideas/'.$proposal->getId().'/decide', ['_token' => $token, 'action' => 'decline', 'note' => 'No.']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testADeclineTellsTheProposerAndTakesAnOpenProposalOffTheTab(): void
    {
        $author = $this->rider('decline-author');
        $follower = $this->rider('decline-follower');
        $this->client->loginUser($this->rider('decline-admin', ['ROLE_ADMIN']));

        $waiting = $this->proposal($author, 'Waiting to be declined', ProposalStatus::Pending);
        $this->decideProposal($waiting, ['action' => 'decline'], 422);
        $this->decideProposal($waiting, ['action' => 'decline', 'note' => 'Out of scope.']);
        self::assertSame(ProposalStatus::Declined, $this->reloadProposal($waiting)->getStatus());
        self::assertSame('Out of scope.', $this->messagesOf($author, UserMessageKind::RoadmapIdeaOutcome)[0]->getBodyText());
        // A declined proposal can still be opened later, but not promoted straight away.
        $this->decideProposal($waiting, ['action' => 'promote', 'group' => 'later'], 422);
        $this->decideProposal($waiting, ['action' => 'open']);
        self::assertSame(ProposalStatus::Open, $this->reloadProposal($waiting)->getStatus());

        $open = $this->proposal($author, 'Open, then declined', ProposalStatus::Open);
        $this->db()->executeStatement('INSERT INTO roadmap_follow (user_id, item_ref, created_at) VALUES (:u, :r, NOW()), (:a, :r, NOW())', ['u' => $follower->getId(), 'a' => $author->getId(), 'r' => $open->getRef()]);
        $this->db()->executeStatement('INSERT INTO roadmap_vote (user_id, item_ref, created_at) VALUES (:u, :r, NOW())', ['u' => $follower->getId(), 'r' => $open->getRef()]);
        $this->decideProposal($open, ['action' => 'decline', 'note' => 'Plans changed.']);
        self::assertSame(['messages.body.roadmap_moved_removed'], $this->bodies($follower, UserMessageKind::RoadmapItemMoved));
        self::assertSame([], $this->bodies($author, UserMessageKind::RoadmapItemMoved));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM roadmap_vote WHERE item_ref = :r', ['r' => $open->getRef()]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM roadmap_follow WHERE item_ref = :r', ['r' => $open->getRef()]));
    }

    public function testTheItemsDeskAddsRewordsMovesOrdersAndCloses(): void
    {
        $follower = $this->rider('items-follower');
        $this->client->loginUser($this->rider('items-admin', ['ROLE_ADMIN']));

        // Refused: no text at all, a body without a title, a bad issue.
        $token = $this->deskToken('/moderate/roadmap/new');
        $this->client->request('POST', '/moderate/roadmap/new', ['_token' => $token, 'group' => 'later', 'text' => []]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/moderate/roadmap/new', ['_token' => $token, 'group' => 'later', 'text' => ['en' => ['title' => '', 'body' => 'Orphan body']]]);
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/moderate/roadmap/new', ['_token' => $token, 'group' => 'later', 'issue' => 'abc', 'text' => ['en' => ['title' => 'T', 'body' => '']]]);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/moderate/roadmap/new', ['_token' => $token, 'group' => 'later', 'issue' => '#42', 'text' => [
            'en' => ['title' => 'Desk-made item.', 'body' => 'Made on the desk.'],
            'nl' => ['title' => 'Punt van de desk', 'body' => ''],
        ]]);
        self::assertResponseStatusCodeSame(303);
        $this->em()->clear();
        $item = $this->em()->getRepository(RoadmapItem::class)->findOneBy(['issue' => 42]);
        self::assertInstanceOf(RoadmapItem::class, $item);
        self::assertSame('Desk-made item', $item->textIn('en')?->getTitle());
        self::assertSame(['en', 'nl'], array_keys($item->getTexts()));
        $this->db()->executeStatement('INSERT INTO roadmap_follow (user_id, item_ref, created_at) VALUES (:u, :r, NOW())', ['u' => $follower->getId(), 'r' => $item->getRef()]);
        $this->db()->executeStatement('INSERT INTO roadmap_vote (user_id, item_ref, created_at) VALUES (:u, :r, NOW())', ['u' => $follower->getId(), 'r' => $item->getRef()]);

        // Rewording tells nobody and can drop a language; a group change is a move.
        $url = '/moderate/roadmap/'.$item->getId();
        $this->client->request('POST', $url, ['_token' => $this->deskToken($url), 'group' => 'later', 'issue' => '', 'text' => ['en' => ['title' => 'Reworded', 'body' => '']]]);
        self::assertResponseStatusCodeSame(303);
        self::assertSame([], $this->bodies($follower, UserMessageKind::RoadmapItemMoved));
        $item = $this->reloadItem($item);
        self::assertSame(['en'], array_keys($item->getTexts()));
        self::assertNull($item->getIssue());

        $this->client->request('POST', $url, ['_token' => $this->deskToken($url), 'group' => 'now', 'text' => ['en' => ['title' => 'Reworded', 'body' => '']]]);
        self::assertSame('now', $this->reloadItem($item)->getStatus());
        self::assertSame(['messages.body.roadmap_moved_now'], $this->bodies($follower, UserMessageKind::RoadmapItemMoved));

        // Order inside a group: a second item lands below, and up swaps them.
        $second = $this->item('now', ['en' => ['Second in now', '']]);
        $this->client->request('GET', '/moderate/roadmap');
        $shiftToken = (string) $this->client->getCrawler()->filter('form.rdesk-shift input[name="_token"]')->first()->attr('value');
        $before = [$this->reloadItem($item)->getPosition(), $this->reloadItem($second)->getPosition()];
        self::assertLessThan($before[1], $before[0]);
        $this->client->request('POST', '/moderate/roadmap/'.$second->getId().'/shift', ['_token' => $shiftToken, 'dir' => 'up']);
        self::assertGreaterThan($this->reloadItem($second)->getPosition(), $this->reloadItem($item)->getPosition());

        // Shipped: off the roadmap, followers told, marks gone.
        $this->client->request('POST', $url, ['_token' => $this->deskToken($url), 'do' => 'ship']);
        self::assertResponseRedirects('/moderate/roadmap', 303);
        $item = $this->reloadItem($item);
        self::assertSame(ItemState::Shipped, $item->getState());
        self::assertNotNull($item->getClosedAt());
        self::assertSame(['messages.body.roadmap_moved_now', 'messages.body.roadmap_moved_shipped'], $this->bodies($follower, UserMessageKind::RoadmapItemMoved));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM roadmap_vote WHERE item_ref = :r', ['r' => $item->getRef()]));
        $this->client->request('GET', '/roadmap');
        self::assertCount(0, $this->client->getCrawler()->filter('#item-'.$item->getId()));

        // A closed item takes no more changes.
        $this->expectException(RoadmapRefused::class);
        $this->desk()->close($this->reloadItem($item), ItemState::Removed);
    }

    public function testAPictureGoesPublicOnlyWhenTheRiderAllowedItAndTheDeskChoseIt(): void
    {
        $author = $this->rider('pic-author');
        $this->client->loginUser($this->rider('pic-admin', ['ROLE_ADMIN']));

        // Not allowed by the rider: the desk's tick changes nothing.
        $private = $this->proposal($author, 'Private picture', ProposalStatus::Pending);
        $this->picture($private, false);
        $this->decideProposal($private, ['action' => 'open', 'picture_offered' => '1', 'show_picture' => '1']);
        self::assertFalse($this->imageOf($private)->isShown());
        $this->client->request('GET', '/roadmap/pictures/'.$private->getId());
        self::assertResponseStatusCodeSame(404);

        // Allowed, but the desk has not chosen it yet.
        $allowed = $this->proposal($author, 'Allowed picture', ProposalStatus::Pending);
        $this->picture($allowed, true);
        $this->decideProposal($allowed, ['action' => 'open']);
        $this->client->request('GET', '/roadmap/pictures/'.$allowed->getId());
        self::assertResponseStatusCodeSame(404);

        // Allowed and chosen: public on the Proposals tab and at its own address.
        $this->decideProposal($allowed, ['action' => 'open', 'picture_offered' => '1', 'show_picture' => '1']);
        $this->client->request('GET', '/roadmap/pictures/'.$allowed->getId());
        self::assertResponseIsSuccessful();
        self::assertSame('webp bytes', $this->client->getResponse()->getContent());
        self::assertStringContainsString('public', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $row = $this->client->request('GET', '/roadmap?tab=proposals')->filter('#idea-'.$allowed->getId());
        self::assertCount(1, $row->filter('.rpic img'));

        // Promoted: the picture comes along to the item.
        $this->decideProposal($allowed, ['action' => 'promote', 'group' => 'later']);
        $item = $this->reloadProposal($allowed)->getPromotedItem();
        self::assertInstanceOf(RoadmapItem::class, $item);
        self::assertCount(1, $this->client->request('GET', '/roadmap')->filter('#item-'.$item->getId().' .rpic img'));
        $this->client->request('GET', '/roadmap/pictures/'.$allowed->getId());
        self::assertResponseIsSuccessful();

        // The item ships: off the page, and so is its picture.
        $this->desk()->close($this->reloadItem($item), ItemState::Shipped);
        $this->client->request('GET', '/roadmap/pictures/'.$allowed->getId());
        self::assertResponseStatusCodeSame(404);

        // A decline hides a shown picture for good.
        $declined = $this->proposal($author, 'Declined picture', ProposalStatus::Pending);
        $this->picture($declined, true);
        $this->decideProposal($declined, ['action' => 'open', 'picture_offered' => '1', 'show_picture' => '1']);
        $this->decideProposal($declined, ['action' => 'decline', 'note' => 'No.']);
        self::assertFalse($this->imageOf($declined)->isShown());
    }

    public function testDeletingAnAccountTakesItsMarksAndPrivateProposalsAndKeepsPublicOnes(): void
    {
        $rider = $this->rider('leaving');
        $waiting = $this->proposal($rider, 'Waiting, goes', ProposalStatus::Pending);
        $public = $this->proposal($rider, 'Open, stays', ProposalStatus::Open);
        $item = $this->item('later', ['en' => ['Voted item', '']]);
        $this->db()->executeStatement('INSERT INTO roadmap_vote (user_id, item_ref, created_at) VALUES (:u, :r, NOW())', ['u' => $rider->getId(), 'r' => $item->getRef()]);
        $this->db()->executeStatement('INSERT INTO roadmap_follow (user_id, item_ref, created_at) VALUES (:u, :r, NOW())', ['u' => $rider->getId(), 'r' => $public->getRef()]);
        $waitingId = (int) $waiting->getId();

        static::getContainer()->get(RoadmapDeletionHook::class)->preDelete($rider);

        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM roadmap_vote WHERE user_id = :u', ['u' => $rider->getId()]));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM roadmap_follow WHERE user_id = :u', ['u' => $rider->getId()]));
        self::assertFalse($this->db()->fetchOne('SELECT id FROM roadmap_proposal WHERE id = :id', ['id' => $waitingId]));
        self::assertNull($this->db()->fetchOne('SELECT user_id FROM roadmap_proposal WHERE id = :id', ['id' => $public->getId()]));
    }

    public function testFiveProposalsADayIsTheLimit(): void
    {
        // On the service, not through requests: the test kernel empties
        // in-memory limiter pools between requests.
        $rider = $this->rider('busy-proposer');
        $intake = static::getContainer()->get(ProposalIntake::class);
        for ($i = 1; $i <= 5; ++$i) {
            $intake->submit($rider, ['title' => 'Proposal number '.$i, 'problem' => 'A long enough problem.', 'idea' => 'A long enough idea.'], null, 'en');
        }
        try {
            $intake->submit($rider, ['title' => 'Proposal number 6', 'problem' => 'A long enough problem.', 'idea' => 'A long enough idea.'], null, 'en');
            self::fail('A sixth proposal in a day was taken.');
        } catch (RoadmapRefused $e) {
            self::assertSame('roadmap.propose.error_too_many', $e->messageKey());
        }
        self::assertSame(5, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM roadmap_proposal WHERE title LIKE 'Proposal number %'"));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function db(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function desk(): ItemDesk
    {
        return static::getContainer()->get(ItemDesk::class);
    }

    private function say(bool $on): void
    {
        static::getContainer()->get(SystemSettingsWriter::class)->set(SettingsRegistry::ROADMAP_SAY_LIVE, $on ? 1 : 0, null);
    }

    /** @param list<string> $roles */
    private function rider(string $name, array $roles = [], bool $verified = true): User
    {
        $user = (new User())->setEmail($name.'@roadmap-test.cyclingcommons.org');
        $user->setPassword('x');
        $user->setDisplayName('Roadmap '.$name);
        $user->setRoles($roles);
        if ([] !== $roles) {
            $user->setTotpSecret('JBSWY3DPEHPK3PXP');
            $user->setTwoFaEnabled(true);
        }
        $user->setEmailVerified($verified);
        if ($verified) {
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function proposal(User $author, string $title, ProposalStatus $status, string $locale = 'en'): RoadmapProposal
    {
        $proposal = new RoadmapProposal((int) $author->getId(), $title, 'What is missing today.', 'What I would like.', null, $locale);
        if (ProposalStatus::Pending !== $status) {
            $proposal->decide($status, ProposalStatus::Declined === $status ? 'Not now.' : null, (int) $author->getId());
        }
        $this->em()->persist($proposal);
        $this->em()->flush();

        return $proposal;
    }

    /** @param array<string, array{0: string, 1: string}> $texts */
    private function item(string $group, array $texts): RoadmapItem
    {
        $input = [];
        foreach ($texts as $locale => [$title, $body]) {
            $input[$locale] = ['title' => $title, 'body' => $body];
        }

        return $this->desk()->create($group, $input, null);
    }

    private function picture(RoadmapProposal $proposal, bool $mayShow): void
    {
        $image = RoadmapProposalImage::pending($proposal, 'raw bytes', $mayShow);
        $image->markReady(new StoredImage('image/webp', 'webp bytes', 4, 3));
        $this->em()->persist($image);
        $this->em()->flush();
    }

    private function imageOf(RoadmapProposal $proposal): RoadmapProposalImage
    {
        $this->em()->clear();
        $image = $this->em()->getRepository(RoadmapProposalImage::class)->findOneBy(['proposal' => $proposal->getId()]);
        self::assertInstanceOf(RoadmapProposalImage::class, $image);

        return $image;
    }

    private function reloadProposal(RoadmapProposal $proposal): RoadmapProposal
    {
        $this->em()->clear();
        $fresh = $this->em()->find(RoadmapProposal::class, $proposal->getId());
        self::assertInstanceOf(RoadmapProposal::class, $fresh);

        return $fresh;
    }

    private function reloadItem(RoadmapItem $item): RoadmapItem
    {
        $this->em()->clear();
        $fresh = $this->em()->find(RoadmapItem::class, $item->getId());
        self::assertInstanceOf(RoadmapItem::class, $fresh);

        return $fresh;
    }

    private function markToken(string $url): string
    {
        return (string) $this->client->request('GET', $url)->filter('form[data-roadmap-mark] input[name="_token"]')->first()->attr('value');
    }

    private function deskToken(string $url): string
    {
        return (string) $this->client->request('GET', $url)->filter('form.decide input[name="_token"]')->attr('value');
    }

    /** @return array{on: bool, votes: int} */
    private function postMark(string $token, string $ref, string $do): array
    {
        $this->client->request('POST', '/roadmap/mark', ['_token' => $token, 'ref' => $ref, 'do' => $do], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();

        /* @var array{on: bool, votes: int} */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $fields */
    private function decideProposal(RoadmapProposal $proposal, array $fields, int $status = 303): void
    {
        $url = '/moderate/ideas/'.$proposal->getId();
        $token = $this->deskToken($url);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', $url.'/decide', $fields + ['_token' => $token]);
        self::assertResponseStatusCodeSame($status);
    }

    /** @return list<?string> the body keys of a rider's messages of one kind, oldest first */
    private function bodies(User $rider, UserMessageKind $kind): array
    {
        return array_map(static fn (UserMessage $m): ?string => $m->getBodyKey(), $this->messagesOf($rider, $kind));
    }

    /** @return list<UserMessage> */
    private function messagesOf(User $rider, UserMessageKind $kind): array
    {
        $this->em()->clear();

        return $this->em()->getRepository(UserMessage::class)->findBy(['userId' => $rider->getId(), 'kind' => $kind], ['id' => 'ASC']);
    }
}
