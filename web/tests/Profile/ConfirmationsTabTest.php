<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Profile;

use App\Catalog\Entity\Item;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Place confirmations have their own account tab (owner 2026-10-03): "still
 * there" or "potable" says a place is real, not that it is the best, so they
 * are not listed under Votes.
 */
final class ConfirmationsTabTest extends WebTestCase
{
    public function testAConfirmationIsListedUnderConfirmationsNotVotes(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('conf-tab-'.bin2hex(random_bytes(4)).'@example.com')->setDisplayName('Confirmer');
        $user->setEmailVerified(true)->setRoles([])->setPassword('x');
        $em->persist($user);
        $item = (new Item())->setLetter('B')->setName('Tap Under Test')
            ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')->setCountryCode('BE')
            ->setState(ItemState::Verified)->setSource(ItemSource::Osm)->setSourceRef('node/'.random_int(1, 999999))->setAttributes([]);
        $em->persist($item);
        $em->flush();
        static::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO item_confirmation (item_id, user_id, stance, source, by_curator, created_at, updated_at)
             VALUES (?, ?, 'potable', 'drawer', false, NOW(), NOW())",
            [$item->getId(), $user->getId()],
        );
        $client->loginUser($user);

        $crawler = $client->request('GET', '/account/contributions?tab=confirmations');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Tap Under Test', $crawler->filter('#p-confirmations')->text());
        self::assertStringNotContainsString('Tap Under Test', $crawler->filter('#p-votes')->text());
        self::assertCount(1, $crawler->filter('.dtabs a[data-pane="confirmations"]'));
        self::assertStringContainsString('No votes yet.', $crawler->filter('#p-votes')->text());
    }

    /** Owner 2026-10-03: the list pages, on its own key, landing on its own tab. */
    public function testTheConfirmationsListPages(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $db = static::getContainer()->get(Connection::class);
        $user = (new User())->setEmail('conf-page-'.bin2hex(random_bytes(4)).'@example.com')->setDisplayName('Pager');
        $user->setEmailVerified(true)->setRoles([])->setPassword('x');
        $em->persist($user);
        $em->flush();
        for ($i = 0; $i < 25; ++$i) {
            $item = (new Item())->setLetter('B')->setName(sprintf('Tap %02d', $i))
                ->setGeom('{"type":"Point","coordinates":[5.5,50.5]}')->setCountryCode('BE')
                ->setState(ItemState::Verified)->setSource(ItemSource::Osm)->setSourceRef('node/'.random_int(1, 99999999))->setAttributes([]);
            $em->persist($item);
            $em->flush();
            $db->executeStatement(
                "INSERT INTO item_confirmation (item_id, user_id, stance, source, by_curator, created_at, updated_at)
                 VALUES (?, ?, 'exists', 'drawer', false, NOW() - make_interval(mins => ?), NOW())",
                [$item->getId(), $user->getId(), $i],
            );
        }
        $client->loginUser($user);

        $first = $client->request('GET', '/account/contributions?tab=confirmations');
        $second = $client->request('GET', '/account/contributions?tab=confirmations&cpage=2');

        self::assertCount(20, $first->filter('#p-confirmations .q-item'));
        self::assertCount(5, $second->filter('#p-confirmations .q-item'));
        self::assertStringContainsString('25', $first->filter('#p-confirmations .sec-count')->text(), 'the count is the total, not the page');
        $next = (string) $first->filter('#p-confirmations a[rel="next"]')->attr('href');
        self::assertStringContainsString('cpage=2', $next);
        self::assertStringContainsString('tab=confirmations', $next);
    }
}
