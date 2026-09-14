<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Where the camera stood, for each cached Commons photo.
 *
 * A scenic view (letter P) shows a photo only when the camera stood within
 * 250 m of the pin (App\Catalog\ScenicPhotoRule). A photo taken somewhere else
 * tells the rider they will see that view from the pin, and they will not.
 * Commons records the camera as the file's primary coordinate of type
 * "camera", so the fetch asks for it and keeps it here.
 *
 * `camera_checked_at` separates "asked, and Commons has no camera point" from
 * "never asked", which is what the backfill for rows fetched before these
 * columns existed needs (app:media:backfill-commons-camera).
 *
 * @see docs/specs/photo-uploads.md §5f
 * @see docs/specs/scenic-views.md §8
 */
final class Version20260914140000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'commons_photo: camera_lat, camera_lng, camera_checked_at';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commons_photo ADD camera_lat DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commons_photo ADD camera_lng DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE commons_photo ADD camera_checked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN commons_photo.camera_lat IS 'Latitude the camera stood at: the file''s primary Commons coordinate of type camera, with at least 3 decimals. NULL when Commons records none. ScenicPhotoRule reads it (scenic-views.md §8).'");
        $this->addSql("COMMENT ON COLUMN commons_photo.camera_lng IS 'Longitude of the same camera point. Set together with camera_lat, or both NULL.'");
        $this->addSql("COMMENT ON COLUMN commons_photo.camera_checked_at IS 'When Commons was last asked for the camera point. NULL = never asked; set with camera_lat NULL = asked, and there is none.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commons_photo DROP camera_checked_at');
        $this->addSql('ALTER TABLE commons_photo DROP camera_lng');
        $this->addSql('ALTER TABLE commons_photo DROP camera_lat');
    }
}
