<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\Entity\Item;
use App\Catalog\Entity\Submission;
use App\Catalog\Import\OutboundLinks;
use App\Catalog\ItemSource;
use App\Catalog\ItemState;
use App\Entity\User;
use App\Service\ContributionStubInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * The wizard's outbound-links field (catalog-data-model.md §7 `links`).
 *
 * The editor posts ONE hidden JSON field, the way the climb route and the
 * segment endpoints already do. Three things have to be true about that, and
 * each is a way the feature would be quietly wrong rather than visibly broken:
 *
 *  - it must be decoded BEFORE the change diff, or a save that touched nothing
 *    compares a JSON string to a stored array, reports a change every time, and
 *    sends a curator work that does not exist;
 *  - `OutboundLinks` must gate it, because the browser's caps are a courtesy
 *    and this is the one door that could skip the rule;
 *  - an emptied editor must REMOVE the links rather than do nothing, or a rider
 *    cannot take a link down.
 *
 * Test isolation: DAMA\DoctrineTestBundle wraps each test in a rolled-back
 * transaction.
 */
final class OutboundLinksEditorTest extends WebTestCase
{
    /** @param array<string, mixed> $attributes */
    private function item(string $ref, array $attributes = []): Item
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = (new Item())->setLetter('Q')->setName('Muiderslot '.$ref)
            ->setGeom('{"type":"Point","coordinates":[5.0718,52.3341]}')->setCountryCode('BE')
            ->setState(ItemState::Unverified)->setSource(ItemSource::Manual)->setSourceRef('manual:'.$ref)
            ->setAttributes($attributes);
        $em->persist($item);
        $em->flush();

        return $item;
    }

    private function user(string $email): User
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail($email);
        $user->setPassword('x');
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function service(): ContributionStubInterface
    {
        return static::getContainer()->get(ContributionStubInterface::class);
    }

    /** @param array<string, mixed> $details */
    private function submit(Item $item, User $user, array $details): Submission
    {
        $receipt = $this->service()->submit('improve', [
            '_item_id' => $item->getId(), 'type' => 'history-culture',
            'details' => $details, 'extras' => [],
            'lat' => '52.3341', 'lng' => '5.0718',
        ], $user);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $em->find(Submission::class, $receipt->submissionId);
    }

    /** @var list<array<string, mixed>> */
    private const array TWO_LEVEL = [
        ['label' => 'Wikipedia', 'urls' => [
            ['url' => 'https://en.wikipedia.org/wiki/Muiderslot', 'locale' => 'en'],
            ['url' => 'https://nl.wikipedia.org/wiki/Muiderslot', 'locale' => 'nl'],
        ]],
    ];

    public function testTheEditorsJsonBecomesTheStoredTwoLevelArray(): void
    {
        self::bootKernel();
        $item = $this->item('links-add');
        $sub = $this->submit($item, $this->user('links-add@test.test'), [
            'links' => json_encode(self::TWO_LEVEL, \JSON_THROW_ON_ERROR),
        ]);

        self::assertArrayHasKey('links', $sub->getChanges());
        // The ARRAY, not the string it arrived as: everything downstream (the
        // drawer, the diff, the moderation card) reads the same shape the
        // importer writes.
        self::assertSame(self::TWO_LEVEL, $sub->getChanges()['links']['now']);
    }

    /**
     * The decode happens before the diff. Re-posting what is already stored
     * must produce no change at all - otherwise every save of an item that has
     * links manufactures a curator task.
     */
    public function testResubmittingTheSameLinksIsNotAChange(): void
    {
        self::bootKernel();
        $item = $this->item('links-same', ['links' => self::TWO_LEVEL]);

        $this->expectException(ValidationFailedException::class);
        // Nothing else changed either, so the wizard's own "nothing changed"
        // guard is what fires - which is the proof that `links` did not
        // register as a difference.
        $this->submit($item, $this->user('links-same@test.test'), [
            'links' => json_encode(self::TWO_LEVEL, \JSON_THROW_ON_ERROR),
        ]);
    }

    /** An emptied editor takes the links down; it is not a no-op. */
    public function testClearingTheEditorRemovesTheLinks(): void
    {
        self::bootKernel();
        $item = $this->item('links-clear', ['links' => self::TWO_LEVEL]);
        $sub = $this->submit($item, $this->user('links-clear@test.test'), ['links' => '']);

        self::assertArrayHasKey('links', $sub->getChanges());
        self::assertSame(self::TWO_LEVEL, $sub->getChanges()['links']['was']);
        self::assertNull($sub->getChanges()['links']['now']);
    }

    /**
     * The server is the rule, not the browser. Every one of these passes a
     * client that has been edited or bypassed, and each must be refused here.
     *
     * @return iterable<string, array{0: mixed}>
     */
    public static function badLinks(): iterable
    {
        yield 'not json at all' => ['{oh no'];
        yield 'not a list' => ['{"label":"Wikipedia"}'];
        yield 'http, not https' => ['[{"urls":[{"url":"http://example.org"}]}]'];
        yield 'the official-site slot, which has its own field' => [
            '[{"label":"Official site","urls":[{"url":"https://example.org"}]}]',
        ];
        yield 'past the destination cap' => [
            '[{"urls":[{"url":"https://a.org"}]},{"urls":[{"url":"https://b.org"}]},'
            .'{"urls":[{"url":"https://c.org"}]},{"urls":[{"url":"https://d.org"}]},'
            .'{"urls":[{"url":"https://e.org"}]}]',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badLinks')]
    public function testTheServerRefusesWhatTheBrowserMightNotHave(mixed $raw): void
    {
        self::bootKernel();
        $item = $this->item('links-bad-'.substr(md5((string) $raw), 0, 8));

        $this->expectException(ValidationFailedException::class);
        $this->submit($item, $this->user('links-bad-'.substr(md5((string) $raw), 0, 8).'@test.test'), [
            'links' => $raw,
        ]);
    }

    /**
     * The editor reaches the page at all.
     *
     * Three pieces have to line up and any one of them failing is silent: the
     * registry has to offer a Links field for this letter, the form has to
     * render it as the marked hidden input, and the caps have to arrive from
     * OutboundLinks rather than from a number typed into the template. The
     * stored value is checked in the field too, because a rider opening an item
     * that already has links must see them rather than an empty editor that
     * will erase them on save.
     */
    public function testTheEditorAndItsCapsReachTheImprovePage(): void
    {
        $client = static::createClient();
        $item = $this->item('links-render', ['links' => self::TWO_LEVEL]);
        $client->loginUser($this->user('links-render@test.test'));

        $client->request('GET', '/improve?item='.$item->getId().'&type=J');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('data-links-editor', $html, 'the marked hidden input is rendered');
        // AssetMapper hashes the filename; match the stem so an asset rebuild
        // does not fail this for no reason.
        self::assertMatchesRegularExpression('#/contribute/links-editor-[^"]*\.js#', $html,
            'and its script is loaded');
        // The caps come from the constants, so raising one is a single edit.
        self::assertStringContainsString('"maxEntries":'.OutboundLinks::MAX_ENTRIES, $html);
        self::assertStringContainsString('"maxUrls":'.OutboundLinks::MAX_URLS_PER_ENTRY, $html);
        // The stored value is in the field for the editor to read back.
        self::assertStringContainsString('en.wikipedia.org', $html);
    }
}
