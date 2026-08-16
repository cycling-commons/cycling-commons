<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The media quarantine and immutable published keys
 * (docs/specs/media-storage-architecture.md §3, §4; media plan tasks 2, 4, 5).
 *
 * Three columns' worth of change, each of which exists because a fact that used
 * to be derived has to become a fact that was recorded:
 *
 *  - `status` widens to 16 so `pending_scan` fits. A quarantined row is one
 *    whose bytes are in the private bucket and whose objects do not exist.
 *  - `storage_shard` is where the bytes ACTUALLY went, as opposed to
 *    `continent`, which is where the photo was. They are the same string today.
 *    They were already NOT the same for any continent with no bucket of its
 *    own: the object was written to the default shard and addressed at a
 *    public base with nothing behind it. Backfilled from `continent`, which is
 *    correct for every row that exists, because EU is the only shard there is.
 *  - `revision` is the `<rev>` in published/<uuid>/<rev>/…, minted per
 *    processing run so a published key never changes meaning and the proxy in
 *    front of it can cache for a year (§4).
 *
 * **Existing rows are left with a NULL revision on purpose, and the deploy is
 * not finished until `app:media:backfill-keys` has run.** The objects of an
 * older photo are at the mutable `photos/<uuid>/…`; stamping a revision here
 * would point every row at a key nothing has written yet, and the images would
 * break the instant this migration committed. The command mints the revision
 * and copies the objects in one step per row, so a photo moves from addressable
 * to addressable with no window in between. Until it runs, a not-yet-backfilled
 * photo reads as "nothing published", which is the same thing every other
 * revision-less row means and is handled everywhere as such.
 *
 * It is a one-off over a handful of rows: no production photos exist yet, which
 * is exactly why task 5 had to land before any real traffic.
 */
final class Version20260816210000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'media_upload: pending_scan status, storage_shard, and the immutable published revision.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media_upload ALTER COLUMN status TYPE VARCHAR(16)');
        $this->addSql('ALTER TABLE media_upload ADD storage_shard VARCHAR(16)');
        $this->addSql('ALTER TABLE media_upload ADD revision VARCHAR(12) DEFAULT NULL');
        $this->addSql('UPDATE media_upload SET storage_shard = UPPER(continent)');
        $this->addSql('ALTER TABLE media_upload ALTER COLUMN storage_shard SET NOT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // A quarantined row cannot be expressed in the narrower column, and it
        // has no published objects to fall back to, so it is dropped rather
        // than silently reinterpreted as something a curator should look at.
        $this->addSql("DELETE FROM media_upload WHERE status = 'pending_scan'");
        $this->addSql('ALTER TABLE media_upload DROP COLUMN revision');
        $this->addSql('ALTER TABLE media_upload DROP COLUMN storage_shard');
        $this->addSql('ALTER TABLE media_upload ALTER COLUMN status TYPE VARCHAR(10)');
    }
}
