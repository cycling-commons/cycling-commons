<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Command;

use App\Media\Commons\CommonsPhotoAdmission;
use App\Media\MediaStorage;
use App\Media\PhotoPlace;
use App\Media\ProcessedPhoto;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Removing every stored photo a scenic view may not show
 * (docs/specs/scenic-views.md §8).
 *
 * Every pin here stands at 46.0, 7.8. A camera at 46.001, 7.8 stood about
 * 111 m away, within reach; one at 46.01, 7.8 stood about 1.1 km away.
 */
final class PruneScenicPhotosCommandTest extends KernelTestCase
{
    use CoverageSchema;

    private const array NEAR = [46.001, 7.8];
    private const array FAR = [46.01, 7.8];

    private Connection $db;
    private FilesystemOperator $bucket;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->db = static::getContainer()->get(Connection::class);
        $this->bucket = static::getContainer()->get('media.storage.eu01');
    }

    /** @param array<string, mixed> $attrs */
    private function item(string $letter, string $name, array $attrs): int
    {
        return (int) $this->db->fetchOne(
            "INSERT INTO item (letter, name, geom, country_code, state, source, source_ref, attributes, created_at, updated_at)
             VALUES (:letter, :name, ST_SetSRID(ST_MakePoint(7.8, 46.0), 4326), 'CH', 'unverified', 'wikidata', :ref, CAST(:attrs AS jsonb),
                     NOW() - interval '2 days', NOW() - interval '2 days')
             RETURNING id",
            ['letter' => $letter, 'name' => $name, 'ref' => 'wikidata:Q'.random_int(1, 999999999), 'attrs' => json_encode($attrs, \JSON_THROW_ON_ERROR)],
        );
    }

    /**
     * A Commons file stored in our bucket, as the localiser leaves it.
     *
     * @param array{0: float, 1: float}|null $camera
     */
    private function storedCommons(string $file, ?array $camera, string $credit = 'Jane'): string
    {
        $prefix = 'published/'.bin2hex(random_bytes(8));
        static::getContainer()->get(MediaStorage::class)->store('test-bucket-eu-01', $prefix, new ProcessedPhoto('O', 'L', 'S', 1200, 900));
        $this->db->executeStatement(
            "INSERT INTO commons_photo (file, state, credit, license, storage_bucket, storage_prefix, width, height, attempts, requested_at, ready_at,
                                        camera_lat, camera_lng, camera_checked_at)
             VALUES (:f, 'ready', :credit, 'CC BY-SA 4.0', 'test-bucket-eu-01', :p, 1200, 900, 1, NOW(), NOW(), :lat, :lng, NOW())",
            ['f' => $file, 'credit' => $credit, 'p' => $prefix, 'lat' => $camera[0] ?? null, 'lng' => $camera[1] ?? null],
        );

        return $prefix;
    }

    /**
     * @param array{0: float, 1: float}|null $camera
     *
     * @return array<string, mixed>
     */
    private static function commons(string $file, ?array $camera = null): array
    {
        $entry = ['sm' => 'https://media.test/img/x/sm.webp', 'lg' => 'https://media.test/img/x/lg.webp', 'credit' => 'Jane',
            'license' => 'CC BY-SA 4.0', 'source' => 'https://commons.wikimedia.org/wiki/File:'.rawurlencode(str_replace(' ', '_', $file))];
        if (null !== $camera) {
            $entry['cameraAt'] = $camera;
        }

        return $entry;
    }

    private function upload(int $itemId, ?int $distance): string
    {
        $consent = Uuid::v4()->toRfc4122();
        $this->db->executeStatement(
            "INSERT INTO consent_record (id, user_id, kind, version, text_hash, consented_at) VALUES (:id, 1, 'media-cc-by-sa', 'test', 'x', NOW())",
            ['id' => $consent],
        );
        $id = Uuid::v4()->toRfc4122();
        $this->db->executeStatement(
            "INSERT INTO media_upload (id, user_id, consent_record_id, continent, status, width, height, bytes, gps_distance_m, item_id, created_at, storage_bucket)
             VALUES (:id, 1, :c, 'EU', 'approved', 1200, 900, 4242, :d, :item, NOW(), 'test-bucket-eu-01')",
            ['id' => $id, 'c' => $consent, 'd' => $distance, 'item' => $itemId],
        );

        return $id;
    }

    private function coveragePoi(string $letter, array $tags): void
    {
        self::insertCoveragePoi($this->db, ['letter' => $letter, 'lat' => 46.0, 'lng' => 7.8, 'tags' => $tags]);
    }

    /** @param array<string, mixed> $args */
    private function prune(array $args = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:scenic:prune-photos'));
        $tester->execute($args, ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    /** @return array<string, mixed> */
    private function attributes(int $id): array
    {
        /** @var array<string, mixed> $attrs */
        $attrs = json_decode((string) $this->db->fetchOne('SELECT attributes FROM item WHERE id = :id', ['id' => $id]), true, 512, \JSON_THROW_ON_ERROR);

        return $attrs;
    }

    private function movedRecently(int $id): bool
    {
        return (bool) $this->db->fetchOne("SELECT updated_at > NOW() - interval '1 hour' FROM item WHERE id = :id", ['id' => $id]);
    }

    private function exists(string $table, string $column, string $value): bool
    {
        return (bool) $this->db->fetchOne("SELECT EXISTS (SELECT 1 FROM {$table} WHERE {$column}::text = :v)", ['v' => $value]);
    }

    /**
     * The row of a file whose stored copy was deleted: kept, with no storage,
     * so the refusal is known next time without a download.
     */
    private function settledWithoutStorage(string $file, string $state = 'declined'): bool
    {
        return (bool) $this->db->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM commons_photo WHERE file = :f AND state = :s AND storage_prefix IS NULL AND storage_bucket IS NULL)',
            ['f' => $file, 's' => $state],
        );
    }

    public function testADryRunChangesNothing(): void
    {
        $item = $this->item('P', 'Dry view', ['photo' => self::commons('Prune dry.jpg')]);
        $prefix = $this->storedCommons('Prune dry.jpg', null);
        $upload = $this->upload($item, null);

        $tester = $this->prune();

        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertEquals(['photo' => self::commons('Prune dry.jpg')], $this->attributes($item));
        self::assertFalse($this->movedRecently($item));
        self::assertTrue($this->exists('commons_photo', 'file', 'Prune dry.jpg'));
        self::assertTrue($this->bucket->fileExists($prefix.'/orig.webp'));
        self::assertTrue($this->exists('media_upload', 'id', $upload));
    }

    public function testAPhotoWithNoCameraIsRemovedAndItsUnsharedFileDeleted(): void
    {
        $item = $this->item('P', 'Unknown camera view', ['photo' => self::commons('Prune none.jpg'), 'wikidata' => 'Q1']);
        $prefix = $this->storedCommons('Prune none.jpg', null);

        $tester = $this->prune(['--write' => true]);

        self::assertSame(['wikidata' => 'Q1'], $this->attributes($item));
        self::assertTrue($this->movedRecently($item), 'updated_at moves so the catalog payload version moves');
        self::assertTrue($this->settledWithoutStorage('Prune none.jpg'));
        self::assertFalse($this->bucket->fileExists($prefix.'/orig.webp'));
        self::assertStringContainsString('Prune none.jpg: camera_unknown', $tester->getDisplay(), '-v names the files and why');
    }

    /**
     * A pruned file does not come back on the next drawer open or harvest: the
     * scenic view is refused on the row alone, and a castle may still have it.
     */
    public function testAPrunedFileIsNotAdmittedAgainForTheViewButIsForACastle(): void
    {
        $this->item('P', 'Far camera view', ['photo' => self::commons('Prune again.jpg', self::FAR)]);
        $this->storedCommons('Prune again.jpg', self::FAR);

        $this->prune(['--write' => true]);

        /** @var CommonsPhotoAdmission $admission */
        $admission = static::getContainer()->get(CommonsPhotoAdmission::class);
        self::assertFalse($admission->admissible('Prune again.jpg', new PhotoPlace('P', 46.0, 7.8)));
        self::assertTrue($admission->admissible('Prune again.jpg', new PhotoPlace('Q', 46.0, 7.8)));
    }

    /** A photo naming no author is not shown on a scenic view either, and that refusal is about the file. */
    public function testAPhotoWithNoAuthorIsRemovedAndItsFileRefused(): void
    {
        $entry = ['credit' => 'Wikimedia Commons'] + self::commons('Prune anon.jpg', self::NEAR);
        $item = $this->item('P', 'Anonymous view', ['photo' => $entry]);
        $this->storedCommons('Prune anon.jpg', self::NEAR, 'Wikimedia Commons');

        $this->prune(['--write' => true]);

        self::assertSame([], $this->attributes($item));
        self::assertTrue($this->settledWithoutStorage('Prune anon.jpg', 'unusable'));
    }

    public function testAPhotoWhoseCameraStoodWithinReachIsKept(): void
    {
        $near = self::commons('Prune near.jpg', self::NEAR);
        $item = $this->item('P', 'Near camera view', ['photos' => [$near, self::commons('Prune far.jpg', self::FAR)]]);
        $nearPrefix = $this->storedCommons('Prune near.jpg', self::NEAR);
        $farPrefix = $this->storedCommons('Prune far.jpg', self::FAR);

        $this->prune(['--write' => true]);

        self::assertEquals(['photos' => [$near]], $this->attributes($item));
        self::assertTrue($this->bucket->fileExists($nearPrefix.'/orig.webp'));
        self::assertTrue($this->exists('commons_photo', 'file', 'Prune near.jpg'));
        self::assertFalse($this->bucket->fileExists($farPrefix.'/orig.webp'));
        self::assertTrue($this->settledWithoutStorage('Prune far.jpg'));
    }

    public function testTheCameraRecordedOnTheFileDecidesOverTheEntry(): void
    {
        // The entry predates the camera backfill; commons_photo knows the camera.
        $unstamped = self::commons('Prune unstamped.jpg');
        // The entry carries a camera the file no longer has.
        $stale = self::commons('Prune stale.jpg', self::NEAR);
        $item = $this->item('P', 'Recorded camera view', ['photos' => [$unstamped, $stale]]);
        $this->storedCommons('Prune unstamped.jpg', self::NEAR);
        $this->storedCommons('Prune stale.jpg', null);

        $this->prune(['--write' => true]);

        self::assertEquals(['photos' => [$unstamped]], $this->attributes($item));
        self::assertTrue($this->exists('commons_photo', 'file', 'Prune unstamped.jpg'));
        self::assertTrue($this->settledWithoutStorage('Prune stale.jpg'));
    }

    public function testAFileAnotherLetterStillShowsIsRemovedFromTheViewButKept(): void
    {
        $scenic = $this->item('P', 'Shared view', ['photo' => self::commons('Prune shared.jpg')]);
        $water = $this->item('B', 'Shared fountain', ['photo' => self::commons('Prune shared.jpg')]);
        $prefix = $this->storedCommons('Prune shared.jpg', null);

        $this->prune(['--write' => true]);

        self::assertSame([], $this->attributes($scenic));
        self::assertEquals(['photo' => self::commons('Prune shared.jpg')], $this->attributes($water));
        self::assertTrue($this->exists('commons_photo', 'file', 'Prune shared.jpg'));
        self::assertTrue($this->bucket->fileExists($prefix.'/orig.webp'));
    }

    public function testRiderPhotosAreKeptAndThoseWithoutAUsableDistanceReported(): void
    {
        $item = $this->item('P', 'Rider view', []);
        $near = $this->upload($item, 30);
        $none = $this->upload($item, null);
        $far = $this->upload($item, 900);
        $gallery = [
            ['id' => $near, 'sm' => 'https://media.test/near.webp', 'distanceM' => 30],
            ['id' => $none, 'sm' => 'https://media.test/none.webp', 'distanceM' => null],
            ['id' => $far, 'sm' => 'https://media.test/far.webp', 'distanceM' => 900],
        ];
        $this->db->executeStatement('UPDATE item SET attributes = CAST(:a AS jsonb) WHERE id = :id', ['id' => $item, 'a' => json_encode(['photos' => $gallery], \JSON_THROW_ON_ERROR)]);

        $tester = $this->prune(['--write' => true]);

        self::assertEquals(['photos' => $gallery], $this->attributes($item), 'a person decides about a rider photo, not this command');
        self::assertFalse($this->movedRecently($item));
        foreach ([$near, $none, $far] as $upload) {
            self::assertTrue($this->exists('media_upload', 'id', $upload));
        }
        $display = $tester->getDisplay();
        self::assertStringContainsString('kept for a person', $display);
        self::assertStringContainsString($none, $display);
        self::assertStringContainsString($far, $display);
        self::assertStringNotContainsString($near, $display);
    }

    /**
     * A curator confirmed where the photo was taken, so it is no longer one for
     * a person to decide, whatever the entry itself says.
     */
    public function testARiderPhotoACuratorConfirmedIsNotListedForAPerson(): void
    {
        $item = $this->item('P', 'Confirmed view', []);
        $confirmed = $this->upload($item, null);
        $unconfirmed = $this->upload($item, null);
        $this->db->executeStatement('UPDATE media_upload SET location_confirmed_by = 1, location_confirmed_at = NOW() WHERE id = :id', ['id' => $confirmed]);
        $gallery = [
            ['id' => $confirmed, 'sm' => 'https://media.test/confirmed.webp', 'distanceM' => null],
            ['id' => $unconfirmed, 'sm' => 'https://media.test/unconfirmed.webp', 'distanceM' => null],
        ];
        $this->db->executeStatement('UPDATE item SET attributes = CAST(:a AS jsonb) WHERE id = :id', ['id' => $item, 'a' => json_encode(['photos' => $gallery], \JSON_THROW_ON_ERROR)]);

        $tester = $this->prune(['--write' => true]);

        self::assertEquals(['photos' => $gallery], $this->attributes($item));
        $display = $tester->getDisplay();
        self::assertStringNotContainsString($confirmed, $display);
        self::assertStringContainsString($unconfirmed, $display);
    }

    public function testACoveragePointsCachedFileWithNoCameraIsDeleted(): void
    {
        self::ensureCoverageSchema($this->db);
        $prefix = $this->storedCommons('Prune poi none.jpg', null);
        $this->coveragePoi('P', ['tourism' => 'viewpoint', 'wikimedia_commons' => 'File:Prune_poi_none.jpg']);

        $this->prune(['--write' => true]);

        self::assertTrue($this->settledWithoutStorage('Prune poi none.jpg'));
        self::assertFalse($this->bucket->fileExists($prefix.'/orig.webp'));
    }

    public function testAFileAScenicPointMayShowIsKept(): void
    {
        self::ensureCoverageSchema($this->db);
        $prefix = $this->storedCommons('Prune poi near.jpg', self::NEAR);
        // One point far from the camera refuses it, another at the camera shows it.
        self::insertCoveragePoi($this->db, ['letter' => 'P', 'lat' => 47.0, 'lng' => 8.5, 'tags' => ['wikidata' => 'Q777001']]);
        $this->coveragePoi('P', ['wikidata' => 'Q777001']);
        $this->db->executeStatement("INSERT INTO wikidata_image (qid, file, answered, checked_at) VALUES ('Q777001', 'Prune poi near.jpg', TRUE, NOW())");

        $this->prune(['--write' => true]);

        self::assertTrue($this->exists('commons_photo', 'file', 'Prune poi near.jpg'));
        self::assertTrue($this->bucket->fileExists($prefix.'/orig.webp'));
    }

    public function testAFileAnotherLettersPointShowsIsKept(): void
    {
        self::ensureCoverageSchema($this->db);
        $prefix = $this->storedCommons('Prune poi castle.jpg', null);
        $this->coveragePoi('P', ['image' => 'https://commons.wikimedia.org/wiki/File:Prune_poi_castle.jpg']);
        $this->coveragePoi('O', ['image' => 'File:Prune poi castle.jpg']);

        $this->prune(['--write' => true]);

        self::assertTrue($this->exists('commons_photo', 'file', 'Prune poi castle.jpg'));
        self::assertTrue($this->bucket->fileExists($prefix.'/orig.webp'));
    }

    public function testItemsOfOtherLettersAreUntouched(): void
    {
        $castle = $this->item('O', 'Far castle', ['photo' => self::commons('Prune castle.jpg', self::FAR)]);
        $upload = $this->upload($castle, null);
        $this->storedCommons('Prune castle.jpg', self::FAR);

        $this->prune(['--write' => true]);

        self::assertEquals(['photo' => self::commons('Prune castle.jpg', self::FAR)], $this->attributes($castle));
        self::assertFalse($this->movedRecently($castle));
        self::assertTrue($this->exists('commons_photo', 'file', 'Prune castle.jpg'));
        self::assertTrue($this->exists('media_upload', 'id', $upload));
    }
}
