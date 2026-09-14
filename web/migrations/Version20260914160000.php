<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A curator's confirmation that a rider photo was taken at the pin.
 *
 * A scenic view (letter P) shows a rider photo only when its camera stood
 * within 250 m of the pin (App\Catalog\ScenicPhotoRule). The server reads the
 * file's Global Positioning System (GPS) position once at intake, keeps only
 * the distance (`gps_distance_m`) and strips all metadata from the stored
 * file, so a photo that carried no GPS can never be measured afterwards. A
 * curator who knows the spot can vouch for it instead; these columns record
 * who did and when, and the photo then counts as within range.
 *
 * @see docs/specs/photo-uploads.md §5g
 * @see docs/specs/scenic-views.md §8
 */
final class Version20260914160000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'media_upload: location_confirmed_by, location_confirmed_at';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload ADD location_confirmed_by BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE media_upload ADD location_confirmed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN media_upload.location_confirmed_by IS 'users.id of the curator who confirmed the photo was taken at the pin. NULL = nobody confirmed. A confirmed photo counts as within range on a scenic view (ScenicPhotoRule, scenic-views.md §8).'");
        $this->addSql("COMMENT ON COLUMN media_upload.location_confirmed_at IS 'When that curator confirmed it. Set together with location_confirmed_by, or both NULL.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload DROP location_confirmed_at');
        $this->addSql('ALTER TABLE media_upload DROP location_confirmed_by');
    }
}
