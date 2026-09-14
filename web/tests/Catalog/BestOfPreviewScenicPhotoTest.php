<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\BestOfPreview;
use App\Catalog\ItemType;
use App\Catalog\Season;
use App\Tests\Coverage\CoverageSchema;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The best-of cards follow the scenic photo rule the map drawer follows.
 *
 * A podium card for a scenic view with a photo taken elsewhere would make the
 * same false promise the drawer refuses to make (PhotoValidator), for both
 * halves of the ranking: a curated item's stored photo and a coverage row's
 * cached Commons photo.
 */
final class BestOfPreviewScenicPhotoTest extends KernelTestCase
{
    use CoverageSchema;

    public function testAScenicCardShowsOnlyAPhotoTakenNearItsPin(): void
    {
        self::bootKernel();
        /** @var Connection $db */
        $db = self::getContainer()->get('doctrine.dbal.default_connection');
        self::ensureCoverageSchema($db);

        $rid = (int) $db->fetchOne(
            "INSERT INTO region (slug, name, geom, area_km2, country_code, admin_level, created_at, updated_at)
             VALUES ('best-scenic-photo-test', 'Best scenic photo test',
                     ST_SetSRID(ST_MakeEnvelope(5.0, 50.0, 6.0, 51.0), 4326), 100, 'BE', 4, NOW(), NOW())
             RETURNING id",
        );

        // Every pin below is at 50.4, 5.8: 0.0009 degrees north is about 100 m, 0.0036 about 400 m.
        $insert = "INSERT INTO item (letter, name, geom, country_code, region_id, state, source, source_ref, attributes, created_at, updated_at)
                   VALUES ('P', :name, ST_GeomFromText('POINT(5.8 50.4)', 4326), 'BE', :rid, 'verified', 'manual', :ref, CAST(:attrs AS jsonb), NOW(), NOW())";
        foreach (['Item far' => 50.4036, 'Item near' => 50.4009] as $name => $cameraLat) {
            $db->executeStatement($insert, ['name' => $name, 'rid' => $rid, 'ref' => 'manual:best-'.$name, 'attrs' => json_encode([
                'type' => 'Viewpoint',
                'photo' => ['sm' => 'https://img.test/'.rawurlencode($name).'.webp', 'cameraAt' => [$cameraLat, 5.8], 'credit' => 'Jane Rider', 'license' => 'CC BY-SA 4.0'],
            ], \JSON_THROW_ON_ERROR)]);
        }

        foreach (['Coverage far' => 50.4036, 'Coverage near' => 50.4009] as $name => $cameraLat) {
            $file = 'Best '.$name.'.jpg';
            self::insertCoveragePoi($db, [
                'ref' => 'node/'.(880000 + \strlen($name)),
                'letter' => 'P',
                'name' => $name,
                'tags' => ['tourism' => 'viewpoint', 'wikimedia_commons' => 'File:'.$file],
                'region_id' => $rid,
            ]);
            $db->executeStatement('DELETE FROM commons_photo WHERE file = :f', ['f' => $file]);
            $db->executeStatement(
                "INSERT INTO commons_photo (file, state, credit, license, storage_bucket, storage_prefix, width, height,
                                            requested_at, ready_at, camera_lat, camera_lng, camera_checked_at)
                 VALUES (:f, 'ready', 'Somebody', 'CC BY-SA 4.0', 'test-bucket-eu-01', :p, 1400, 933, NOW(), NOW(), :lat, 5.8, NOW())",
                ['f' => $file, 'p' => 'published/best/'.md5($file), 'lat' => $cameraLat],
            );
        }

        /** @var BestOfPreview $preview */
        $preview = self::getContainer()->get(BestOfPreview::class);
        $photoByName = [];
        foreach ($preview->ranking(ItemType::ScenicViews, Season::Summer, 'BE', regionId: $rid) as $row) {
            $photoByName[$row['name']] = $row['photo']['sm'] ?? null;
        }

        self::assertArrayHasKey('Item far', $photoByName);
        self::assertNull($photoByName['Item far'], 'a stored photo from 400 m away is not the view from the pin');
        self::assertSame('https://img.test/Item%20near.webp', $photoByName['Item near']);
        self::assertArrayHasKey('Coverage far', $photoByName);
        self::assertNull($photoByName['Coverage far'], 'a cached Commons photo from 400 m away is not either');
        self::assertNotNull($photoByName['Coverage near'] ?? null);
    }
}
