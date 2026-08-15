<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The links free-first-fill: artifact rows land in the `links` attribute of
 * the matching wikidata item, validated by OutboundLinks; a curator-edited
 * row is shielded (an approved edit outranks a harvest, ItemUpsert's rule);
 * a violating artifact is refused whole.
 */
final class ImportItemLinksCommandTest extends KernelTestCase
{
    private Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
    }

    private function makeItem(string $ref): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at, imported_at)
             VALUES ('J', 'Links Castle', ST_SetSRID(ST_MakePoint(5.07, 52.33), 4326), 'NL', 'unverified', 'wikidata', :ref, '{\"t\": \"Castle\"}', NOW(), NOW(), NOW())
             RETURNING id",
            ['ref' => $ref],
        );
    }

    /** @param array<string, mixed> $artifact */
    private function run_(array $artifact): CommandTester
    {
        $path = tempnam(sys_get_temp_dir(), 'lnk');
        self::assertNotFalse($path);
        file_put_contents($path, json_encode($artifact, \JSON_THROW_ON_ERROR));
        $tester = new CommandTester((new Application(self::$kernel))->find('app:items:import-links'));
        $tester->execute(['artifact' => $path]);
        unlink($path);

        return $tester;
    }

    private const array LINKS = [
        ['label' => 'Official site', 'urls' => [['url' => 'https://muiderslot.nl']]],
        ['label' => 'Wikipedia', 'urls' => [['url' => 'https://nl.wikipedia.org/wiki/Muiderslot', 'locale' => 'nl']]],
    ];

    public function testLinksLandBesideExistingAttributes(): void
    {
        $id = $this->makeItem('wikidata:Q999901');

        $this->run_(['wikidata:Q999901' => self::LINKS])->assertCommandIsSuccessful();

        $attrs = json_decode((string) $this->db->fetchOne('SELECT attributes FROM item WHERE id = :id', ['id' => $id]), true);
        self::assertSame('Castle', $attrs['t'], 'existing attributes survive');
        self::assertEquals(self::LINKS, $attrs['links']);
    }

    public function testACuratorEditedRowIsLeftAlone(): void
    {
        $id = $this->makeItem('wikidata:Q999902');
        $this->db->executeStatement(
            "INSERT INTO change_history (item_id, field, old_value, new_value, changed_by, changed_at) VALUES (:id, 'note', '\"a\"', '\"b\"', 1, NOW())",
            ['id' => $id],
        );

        $tester = $this->run_(['wikidata:Q999902' => self::LINKS]);

        $tester->assertCommandIsSuccessful();
        $attrs = json_decode((string) $this->db->fetchOne('SELECT attributes FROM item WHERE id = :id', ['id' => $id]), true);
        self::assertArrayNotHasKey('links', $attrs, 'an approved edit outranks a harvest');
        self::assertStringContainsString('1 left alone', $tester->getDisplay());
    }

    public function testAViolatingArtifactIsRefusedWhole(): void
    {
        $id = $this->makeItem('wikidata:Q999903');

        $tester = $this->run_(['wikidata:Q999903' => [['urls' => [['url' => 'http://not-https.example']]]]]);

        self::assertSame(1, $tester->getStatusCode());
        $attrs = json_decode((string) $this->db->fetchOne('SELECT attributes FROM item WHERE id = :id', ['id' => $id]), true);
        self::assertArrayNotHasKey('links', $attrs);
    }
}
