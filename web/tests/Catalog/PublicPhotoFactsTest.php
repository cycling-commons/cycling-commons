<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Catalog;

use App\Catalog\Entity\Region;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Where a photographer stood is never public (docs/specs/photo-uploads.md §5g).
 * A stored photo entry keeps its distance from the pin and the pins it was
 * measured or confirmed at, for PhotoValidator; the public region document
 * serves the photo without them.
 */
final class PublicPhotoFactsTest extends WebTestCase
{
    private const array PRIVATE_KEYS = ['distanceM', 'distancePin', 'confirmedPin', 'locationConfirmed', 'cameraAt'];

    public function testTheRegionDocumentServesPhotosWithoutWhereTheyWereTaken(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $region = (new Region())->setSlug('photo-facts-'.uniqid())->setName('Photo facts')->setCountryCode('BE')
            ->setGeom('{"type":"MultiPolygon","coordinates":[[[[4.0,49.5],[6.5,49.5],[6.5,51.0],[4.0,51.0],[4.0,49.5]]]]}');
        $em->persist($region);
        $em->flush();
        $rid = (int) $region->getId();

        $measured = ['id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'sm' => 'https://img.test/measured-sm.webp', 'lg' => 'https://img.test/measured-lg.webp',
            'credit' => '', 'license' => 'CC BY-SA 4.0', 'distanceM' => 40, 'distancePin' => [50.4, 4.5]];
        $confirmed = ['id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'sm' => 'https://img.test/confirmed-sm.webp', 'lg' => 'https://img.test/confirmed-lg.webp',
            'credit' => '', 'license' => 'CC BY-SA 4.0', 'distanceM' => 900, 'distancePin' => [50.4, 4.5], 'locationConfirmed' => true, 'confirmedPin' => [50.4, 4.5]];
        $commons = ['sm' => 'https://img.test/commons-sm.webp', 'lg' => 'https://img.test/commons-lg.webp',
            'credit' => 'Jane Photographer', 'license' => 'CC BY-SA 4.0', 'cameraAt' => [50.4009, 4.5]];

        $insert = "INSERT INTO item (letter, name, geom, country_code, region_id, state, source, source_ref, attributes, created_at, updated_at)
                   VALUES (:letter, :name, ST_GeomFromText('POINT(4.5 50.4)', 4326), 'BE', :rid, 'verified', 'manual', :ref, CAST(:attrs AS jsonb), now(), now())";
        $db = $em->getConnection();
        $db->executeStatement($insert, ['letter' => 'P', 'name' => 'Facts viewpoint', 'rid' => $rid, 'ref' => 'manual:facts-view',
            'attrs' => json_encode(['type' => 'Viewpoint', 'photos' => [$measured, $confirmed, $commons]], \JSON_THROW_ON_ERROR)]);
        $db->executeStatement($insert, ['letter' => 'B', 'name' => 'Facts tap', 'rid' => $rid, 'ref' => 'manual:facts-tap',
            'attrs' => json_encode(['t' => 'Drinking water', 'photo' => $measured, 'photos' => [$confirmed]], \JSON_THROW_ON_ERROR)]);

        $client->request('GET', '/map/catalog/region/'.$rid.'.json');
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        /** @var array<string, mixed> $doc */
        $doc = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        $served = [];
        foreach (['P', 'B'] as $letter) {
            foreach ($doc[$letter]['features'] as $feature) {
                $props = $feature['properties'];
                foreach (array_merge(isset($props['photo']) ? [$props['photo']] : [], $props['photos'] ?? []) as $photo) {
                    $served[] = $photo['sm'];
                    foreach (self::PRIVATE_KEYS as $key) {
                        self::assertArrayNotHasKey($key, $photo, sprintf('%s on %s is not public', $key, $props['n'] ?? '?'));
                    }
                }
            }
        }
        self::assertContains($measured['sm'], $served, 'the photos are still served');
        self::assertContains($confirmed['sm'], $served);
        self::assertContains($commons['sm'], $served);
        foreach (self::PRIVATE_KEYS as $key) {
            self::assertStringNotContainsString('"'.$key.'"', $body);
        }

        // The stored entry keeps them: PhotoValidator and the curator's hidden-photos view read them.
        $stored = json_decode((string) $db->fetchOne("SELECT attributes FROM item WHERE source_ref = 'manual:facts-view'"), true);
        self::assertSame(40, $stored['photos'][0]['distanceM']);
        self::assertSame([50.4, 4.5], $stored['photos'][1]['confirmedPin']);
    }
}
