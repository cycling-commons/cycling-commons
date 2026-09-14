<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One photo validator: `commons_photo` records its verdicts.
 *
 * PhotoValidator decides every link of a photo to a place. A Commons file it
 * refuses for the place that asked (a scenic view and a camera that is unknown
 * or far) is `declined`: nothing is downloaded, and the row keeps the credit,
 * licence and camera so the refusal is known next time. A refusal about the
 * file itself is `unusable` with the verdict's reason code in `failed_reason`
 * (licence, no_author, non_free, restricted), or `no_file` when Commons has no
 * such file.
 *
 * Rows refused for their licence carry the reason `licence`, which is what
 * `app:media:localise-commons --recheck-licences` re-queues.
 *
 * @see docs/specs/photo-uploads.md §5h
 * @see docs/specs/scenic-views.md §8
 */
final class Version20260914170000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'commons_photo: declined state, PhotoValidator reason codes in failed_reason';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE commons_photo SET failed_reason = 'licence' WHERE failed_reason = 'no_free_licence'");
        $this->addSql("COMMENT ON COLUMN commons_photo.state IS 'pending|ready|unusable|failed|declined, App\\Media\\Commons\\CommonsPhotoState. unusable is a verdict about the file and is terminal; failed is about us and may be retried; declined is PhotoValidator refusing the file for the place that asked, with no stored copy (photo-uploads.md §5h).'");
        $this->addSql("COMMENT ON COLUMN commons_photo.failed_reason IS 'Why the row is not ready: a PhotoValidator reason code (licence, no_author, non_free, restricted, camera_unknown, camera_far), no_file, a PhotoProcessor refusal, or the cause of a failed fetch.'");
        $this->addSql("COMMENT ON COLUMN commons_photo.camera_lat IS 'Latitude the camera stood at: the file''s primary Commons coordinate of type camera, with at least 3 decimals. NULL when Commons records none. PhotoValidator reads it for a scenic view (scenic-views.md §8).'");
        $this->addSql("COMMENT ON COLUMN media_upload.location_confirmed_by IS 'users.id of the curator who confirmed the photo was taken at the pin. NULL = nobody confirmed. A confirmed photo counts as within range on a scenic view (PhotoValidator, scenic-views.md §8).'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE commons_photo SET state = 'unusable' WHERE state = 'declined'");
        $this->addSql("UPDATE commons_photo SET failed_reason = 'no_free_licence' WHERE failed_reason = 'licence'");
    }
}
