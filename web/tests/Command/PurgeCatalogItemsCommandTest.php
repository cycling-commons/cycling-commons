<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Media\MediaStorage;
use App\Media\ProcessedPhoto;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Permanently removing retired catalog items, their records and the photos
 * only they use (docs/specs/scenic-views.md §4).
 */
final class PurgeCatalogItemsCommandTest extends KernelTestCase
{
    use CoverageSchema;

    private Connection $db;
    private FilesystemOperator $bucket;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->bucket = static::getContainer()->get('media.storage.eu01');
    }

    private function item(string $name, string $state, array $attrs = []): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES ('P', :name, ST_SetSRID(ST_MakePoint(7.8, 46.0), 4326), 'CH', :state, 'wikidata', :ref, :attrs, NOW(), NOW())
             RETURNING id",
            ['name' => $name, 'state' => $state, 'ref' => 'wikidata:Q'.random_int(1, 999999999), 'attrs' => json_encode($attrs, \JSON_THROW_ON_ERROR)],
        );
    }

    /** A Commons file stored in our bucket, as the localiser leaves it. */
    private function storedCommons(string $file): string
    {
        $prefix = 'published/'.bin2hex(random_bytes(8));
        static::getContainer()->get(MediaStorage::class)->store('test-bucket-eu-01', $prefix, new ProcessedPhoto('O', 'L', 'S', 1200, 900));
        $this->db->executeStatement(
            "INSERT INTO commons_photo (file, state, credit, license, storage_bucket, storage_prefix, width, height, attempts, requested_at, ready_at)
             VALUES (:f, 'ready', 'Jane', 'CC BY-SA 4.0', 'test-bucket-eu-01', :p, 1200, 900, 1, NOW(), NOW())",
            ['f' => $file, 'p' => $prefix],
        );

        return $prefix;
    }

    private static function photo(string $file): array
    {
        return ['sm' => 'https://media.test/img/x/sm.webp', 'lg' => 'https://media.test/img/x/lg.webp', 'credit' => 'Jane',
            'license' => 'CC BY-SA 4.0', 'source' => 'https://commons.wikimedia.org/wiki/File:'.rawurlencode(str_replace(' ', '_', $file))];
    }

    /** @param array<string, mixed> $args */
    private function run_(array $args): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:catalog:purge-items'));
        $tester->execute($args);

        return $tester;
    }

    private function exists(string $table, string $column, int|string $value): bool
    {
        return (bool) $this->db->fetchOne("SELECT EXISTS (SELECT 1 FROM {$table} WHERE {$column} = :v)", ['v' => $value]);
    }

    public function testADryRunRemovesNothing(): void
    {
        $retired = $this->item('Dufourspitze', 'retired', ['photo' => self::photo('Dufour only.jpg')]);
        $prefix = $this->storedCommons('Dufour only.jpg');

        $tester = $this->run_(['--letter' => 'P', '--state' => 'retired']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Would remove', $tester->getDisplay());
        self::assertTrue($this->exists('item', 'id', $retired));
        self::assertTrue($this->bucket->fileExists($prefix.'/orig.webp'));
    }

    public function testWriteRemovesTheItemItsRecordsAndPhotosNothingElseUses(): void
    {
        $retired = $this->item('Dufourspitze', 'retired', ['photos' => [self::photo('Dufour only.jpg'), self::photo('Shared view.jpg')]]);
        $live = $this->item('Gornergrat', 'unverified', ['photo' => self::photo('Shared view.jpg')]);
        $onlyPrefix = $this->storedCommons('Dufour only.jpg');
        $sharedPrefix = $this->storedCommons('Shared view.jpg');

        $user = (new User())->setEmail('purge-'.uniqid('', true).'@test.test');
        $user->setPassword('x');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $uid = (int) $user->getId();
        $this->db->executeStatement("INSERT INTO change_history (item_id, field, old_value, new_value, changed_by, changed_at) VALUES (:i, 'name', '\"a\"', '\"b\"', :u, NOW())", ['i' => $retired, 'u' => $uid]);
        $this->db->executeStatement("INSERT INTO item_confirmation (item_id, user_id, stance, source, by_curator, created_at, updated_at) VALUES (:i, :u, 'exists', 'drawer', false, NOW(), NOW())", ['i' => $retired, 'u' => $uid]);
        $submission = (int) $this->db->fetchOne(
            "INSERT INTO submission (type, letter, item_id, user_id, status, title, geom, country_code, changes, payload, created_at)
             VALUES ('new', 'P', :i, :u, 'approved', 'Dufourspitze', ST_SetSRID(ST_MakePoint(7.8, 46.0), 4326), 'CH', '{}', '{}', NOW()) RETURNING id",
            ['i' => $retired, 'u' => $uid],
        );
        $this->db->executeStatement(
            "INSERT INTO user_message (user_id, kind, sender, channel, ref_id, ref_label, body_key, body_params, created_at)
             VALUES (:u, 'decision', 'curator', 'submission', :s, 'Dufourspitze', 'x', '{}', NOW())",
            ['u' => $uid, 's' => $submission],
        );

        $tester = $this->run_(['--letter' => 'P', '--state' => 'retired', '--write' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertFalse($this->exists('item', 'id', $retired));
        self::assertFalse($this->exists('change_history', 'item_id', $retired));
        self::assertFalse($this->exists('item_confirmation', 'item_id', $retired));
        self::assertFalse($this->exists('submission', 'id', $submission));
        self::assertFalse((bool) $this->db->fetchOne("SELECT EXISTS (SELECT 1 FROM user_message WHERE channel = 'submission' AND ref_id = :s)", ['s' => $submission]));

        self::assertFalse($this->exists('commons_photo', 'file', 'Dufour only.jpg'), 'a photo only this item used');
        self::assertFalse($this->bucket->fileExists($onlyPrefix.'/orig.webp'));

        self::assertTrue($this->exists('item', 'id', $live));
        self::assertTrue($this->exists('commons_photo', 'file', 'Shared view.jpg'), 'a photo a live item still uses');
        self::assertTrue($this->bucket->fileExists($sharedPrefix.'/orig.webp'));
    }

    public function testAPhotoACoveragePointNamesIsKept(): void
    {
        self::ensureCoverageSchema($this->db);
        $retired = $this->item('Dufourspitze', 'retired', ['photos' => [self::photo('Point tag.jpg'), self::photo('Point wikidata.jpg')]]);
        $tagPrefix = $this->storedCommons('Point tag.jpg');
        $wikidataPrefix = $this->storedCommons('Point wikidata.jpg');
        self::insertCoveragePoi($this->db, ['letter' => 'P', 'tags' => ['wikimedia_commons' => 'File:Point_tag.jpg']]);
        self::insertCoveragePoi($this->db, ['letter' => 'O', 'tags' => ['wikidata' => 'Q777002']]);
        $this->db->executeStatement("INSERT INTO wikidata_image (qid, file, answered, checked_at) VALUES ('Q777002', 'Point wikidata.jpg', TRUE, NOW())");

        $this->run_(['--letter' => 'P', '--state' => 'retired', '--write' => true])->assertCommandIsSuccessful();

        self::assertFalse($this->exists('item', 'id', $retired));
        self::assertTrue($this->exists('commons_photo', 'file', 'Point tag.jpg'));
        self::assertTrue($this->bucket->fileExists($tagPrefix.'/orig.webp'));
        self::assertTrue($this->exists('commons_photo', 'file', 'Point wikidata.jpg'));
        self::assertTrue($this->bucket->fileExists($wikidataPrefix.'/orig.webp'));
    }

    public function testOnlyRetiredItemsCanBePurged(): void
    {
        $live = $this->item('Gornergrat', 'unverified');

        $tester = $this->run_(['--letter' => 'P', '--state' => 'unverified', '--write' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertTrue($this->exists('item', 'id', $live));
    }
}
