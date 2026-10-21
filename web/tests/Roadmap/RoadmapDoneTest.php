<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Roadmap;

use App\Roadmap\Entity\RoadmapItem;
use App\Roadmap\ItemDesk;
use App\Roadmap\ItemState;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Latest additions beside the roadmap, and the Done page
 * (docs/specs/roadmap-and-changelog.md §8.2a).
 *
 * A shipped item moves off the list into both, newest first; a removed item
 * is on neither, because it was dropped, not built.
 */
final class RoadmapDoneTest extends WebTestCase
{
    private KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    public function testTheRoadmapShowsTheNewestShippedItemsBesideTheList(): void
    {
        $this->item('Still being built', null);
        foreach (range(1, 6) as $n) {
            $this->item('Shipped number '.$n, ItemState::Shipped, sprintf('2026-0%d-15', $n));
        }
        $this->item('Dropped idea', ItemState::Removed, '2026-07-01');

        $page = $this->client->request('GET', '/roadmap');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Still being built', $page->filter('.rwrap')->text());
        $latest = $page->filter('.rdone-list a')->each(static fn (Crawler $a): string => $a->text());
        self::assertSame(['Shipped number 6', 'Shipped number 5', 'Shipped number 4', 'Shipped number 3', 'Shipped number 2'], $latest, 'the newest five, newest first');
        self::assertStringNotContainsString('Dropped idea', (string) $this->client->getResponse()->getContent());
        self::assertSame('/roadmap/done', $page->filter('a.rdone-all')->attr('href'));
    }

    public function testWithNothingShippedThereIsNoColumn(): void
    {
        $this->item('Still being built', null);

        $page = $this->client->request('GET', '/roadmap');

        self::assertCount(0, $page->filter('.rdone'));
    }

    public function testTheDonePageListsEveryShippedItemByMonthNewestFirst(): void
    {
        $this->item('Shipped in March', ItemState::Shipped, '2026-03-02');
        $this->item('Shipped in May', ItemState::Shipped, '2026-05-20');
        $this->item('Also in May', ItemState::Shipped, '2026-05-03');
        $this->item('Dropped idea', ItemState::Removed, '2026-05-10');
        $this->item('Still being built', null);

        $page = $this->client->request('GET', '/roadmap/done');

        self::assertResponseIsSuccessful();
        self::assertSame(['May 2026', 'March 2026'], $page->filter('.rmonth h2')->each(static fn (Crawler $h): string => $h->text()));
        self::assertSame(
            ['Shipped in May', 'Also in May', 'Shipped in March'],
            $page->filter('.ritem b')->each(static fn (Crawler $b): string => $b->text()),
        );
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Dropped idea', $html);
        self::assertStringNotContainsString('Still being built', $html);
    }

    public function testTheDonePageSlugIsLocalisedAndEveryLanguageRenders(): void
    {
        $router = static::getContainer()->get('router');
        self::assertSame('/nl/routekaart/klaar', $router->generate('roadmap_done', ['_locale' => 'nl']));
        foreach (['en', 'fr', 'nl', 'de', 'es'] as $locale) {
            $this->client->request('GET', $router->generate('roadmap_done', ['_locale' => $locale]));
            self::assertResponseIsSuccessful($locale);
        }
    }

    private function item(string $title, ?ItemState $closed, string $closedOn = 'now'): RoadmapItem
    {
        /** @var ItemDesk $desk */
        $desk = static::getContainer()->get(ItemDesk::class);
        $item = $desk->create('later', ['en' => ['title' => $title, 'body' => 'Words for '.$title.'.']], null);
        if (null !== $closed) {
            $desk->close($item, $closed);
            /** @var Connection $db */
            $db = static::getContainer()->get(Connection::class);
            $db->executeStatement('UPDATE roadmap_item SET closed_at = :on WHERE id = :id', ['on' => $closedOn, 'id' => $item->getId()]);
            // The page reads through the entity manager: drop the copy that still holds today's date.
            static::getContainer()->get(EntityManagerInterface::class)->clear();
        }

        return $item;
    }
}
