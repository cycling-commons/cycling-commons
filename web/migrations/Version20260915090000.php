<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The pins a rider photo was measured to and confirmed at.
 *
 * A rider photo's distance (`gps_distance_m`) is measured once, to the
 * submission pin, and the file's Global Positioning System (GPS) position is
 * then deleted. A curator's "Taken here" vouches for the item pin as it stood.
 * When the item's pin moves later, neither can be measured again, so a scenic
 * view counts a photo only while (its distance) + (how far the pin now is from
 * the pin it was measured to) is within 250 m, and a confirmation only while
 * the pin has not moved (App\Media\PhotoValidator, owner 2026-09-15). These
 * columns keep those pins, and each rider entry in `item.attributes` `photo` /
 * `photos` carries them as `distancePin` and `confirmedPin`.
 *
 * Backfill, nothing removed: a distance gets the submission's point pin, the one
 * the worker measured to, or the item's pin when the submission has none; a
 * confirmation gets the item's pin. An item whose pin never moved therefore
 * shows the same photos as before. An item whose pin moved after a rider photo
 * was measured now hides that photo when the sum is over 250 m.
 *
 * @see docs/specs/photo-uploads.md §5g, §5h
 * @see docs/specs/scenic-views.md §8
 */
final class Version20260915090000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'media_upload: gps_distance_pin_lat/lng, location_confirmed_pin_lat/lng; rider photo entries carry distancePin, confirmedPin';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload ADD gps_distance_pin_lat DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD gps_distance_pin_lng DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD location_confirmed_pin_lat DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD location_confirmed_pin_lng DOUBLE PRECISION DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN media_upload.gps_distance_pin_lat IS 'Latitude of the submission pin gps_distance_m was measured to. NULL when there is no distance. A scenic view adds how far the item pin has moved from it to the distance (PhotoValidator::reachM(), photo-uploads.md §5g).'");
        $this->addSql("COMMENT ON COLUMN media_upload.gps_distance_pin_lng IS 'Longitude of that pin. Set together with gps_distance_pin_lat.'");
        $this->addSql("COMMENT ON COLUMN media_upload.location_confirmed_pin_lat IS 'Latitude of the item pin the curator confirmed the photo was taken at. The confirmation counts only while the pin has not moved (PhotoValidator, photo-uploads.md §5g).'");
        $this->addSql("COMMENT ON COLUMN media_upload.location_confirmed_pin_lng IS 'Longitude of that pin. Set together with location_confirmed_pin_lat.'");

        // The pin the worker measured to: the submission's point.
        $this->addSql(<<<'SQL'
            UPDATE media_upload mu
               SET gps_distance_pin_lat = ST_Y(s.geom), gps_distance_pin_lng = ST_X(s.geom)
              FROM submission s
             WHERE s.id = mu.submission_id AND mu.gps_distance_m IS NOT NULL
               AND GeometryType(s.geom) = 'POINT'
            SQL);
        // No point submission left to read: the item's pin, as if it never moved.
        $this->addSql(<<<'SQL'
            UPDATE media_upload mu
               SET gps_distance_pin_lat = ST_Y(ST_PointOnSurface(i.geom)), gps_distance_pin_lng = ST_X(ST_PointOnSurface(i.geom))
              FROM item i
             WHERE i.id = mu.item_id AND mu.gps_distance_m IS NOT NULL AND mu.gps_distance_pin_lat IS NULL
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE media_upload mu
               SET location_confirmed_pin_lat = ST_Y(ST_PointOnSurface(i.geom)), location_confirmed_pin_lng = ST_X(ST_PointOnSurface(i.geom))
              FROM item i
             WHERE i.id = mu.item_id AND mu.location_confirmed_at IS NOT NULL
            SQL);

        $this->addSql(<<<SQL
            UPDATE item i
               SET attributes = jsonb_set(i.attributes, '{photos}', (
                       SELECT COALESCE(jsonb_agg({$this->stamped('e.value')} ORDER BY e.ord), '[]'::jsonb)
                         FROM jsonb_array_elements(i.attributes->'photos') WITH ORDINALITY AS e(value, ord)
                   )),
                   updated_at = NOW()
             WHERE jsonb_typeof(i.attributes->'photos') = 'array'
               AND EXISTS (SELECT 1 FROM jsonb_array_elements(i.attributes->'photos') x
                             JOIN media_upload mu ON mu.id::text = x.value->>'id'
                            WHERE mu.gps_distance_pin_lat IS NOT NULL OR mu.location_confirmed_pin_lat IS NOT NULL)
            SQL);
        $this->addSql(<<<SQL
            UPDATE item i
               SET attributes = jsonb_set(i.attributes, '{photo}', {$this->stamped("i.attributes->'photo'")}),
                   updated_at = NOW()
             WHERE jsonb_typeof(i.attributes->'photo') = 'object'
               AND EXISTS (SELECT 1 FROM media_upload mu
                            WHERE mu.id::text = i.attributes->'photo'->>'id'
                              AND (mu.gps_distance_pin_lat IS NOT NULL OR mu.location_confirmed_pin_lat IS NOT NULL))
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE item i
               SET attributes = jsonb_set(i.attributes, '{photos}', (
                       SELECT COALESCE(jsonb_agg(CASE WHEN jsonb_typeof(e.value) = 'object' THEN e.value - 'distancePin' - 'confirmedPin' ELSE e.value END ORDER BY e.ord), '[]'::jsonb)
                         FROM jsonb_array_elements(i.attributes->'photos') WITH ORDINALITY AS e(value, ord)
                   ))
             WHERE jsonb_typeof(i.attributes->'photos') = 'array'
               AND EXISTS (SELECT 1 FROM jsonb_array_elements(i.attributes->'photos') x
                            WHERE jsonb_typeof(x.value) = 'object' AND (jsonb_exists(x.value, 'distancePin') OR jsonb_exists(x.value, 'confirmedPin')))
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE item SET attributes = jsonb_set(attributes, '{photo}', (attributes->'photo') - 'distancePin' - 'confirmedPin')
             WHERE jsonb_typeof(attributes->'photo') = 'object'
               AND (jsonb_exists(attributes->'photo', 'distancePin') OR jsonb_exists(attributes->'photo', 'confirmedPin'))
            SQL);
        $this->addSql('ALTER TABLE media_upload DROP location_confirmed_pin_lng');
        $this->addSql('ALTER TABLE media_upload DROP location_confirmed_pin_lat');
        $this->addSql('ALTER TABLE media_upload DROP gps_distance_pin_lng');
        $this->addSql('ALTER TABLE media_upload DROP gps_distance_pin_lat');
    }

    /**
     * One photo entry with its upload's pins added: `distancePin` when the
     * upload has one, `confirmedPin` when the entry is confirmed and the
     * upload has one. Anything that is not a rider entry stays as it is.
     */
    private function stamped(string $entry): string
    {
        return <<<SQL
            CASE WHEN jsonb_typeof({$entry}) = 'object' THEN {$entry} || COALESCE((
                SELECT CASE WHEN mu.gps_distance_pin_lat IS NOT NULL
                            THEN jsonb_build_object('distancePin', jsonb_build_array(mu.gps_distance_pin_lat, mu.gps_distance_pin_lng))
                            ELSE '{}'::jsonb END
                    || CASE WHEN mu.location_confirmed_pin_lat IS NOT NULL AND {$entry}->'locationConfirmed' = 'true'::jsonb
                            THEN jsonb_build_object('confirmedPin', jsonb_build_array(mu.location_confirmed_pin_lat, mu.location_confirmed_pin_lng))
                            ELSE '{}'::jsonb END
                  FROM media_upload mu WHERE mu.id::text = {$entry}->>'id'
            ), '{}'::jsonb) ELSE {$entry} END
            SQL;
    }
}
