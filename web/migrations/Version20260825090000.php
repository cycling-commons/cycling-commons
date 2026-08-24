<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cached third-party media, keyed by values a re-harvest cannot destroy.
 *
 * Neither table hangs off `coverage_poi`. That table is diff-merged by the
 * harvest, so a column added there is gone the next time its country is
 * re-imported. A Commons filename and a Wikidata QID both survive that, so they
 * are the keys.
 *
 * @see docs/specs/coverage-provider.md §7
 */
final class Version20260825090000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'commons_photo / wikidata_image: cached Commons media for coverage POIs, keyed by filename and QID';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE commons_photo (
                id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                file VARCHAR(240) NOT NULL,
                state VARCHAR(12) NOT NULL,
                credit VARCHAR(255) DEFAULT NULL,
                credit_user VARCHAR(255) DEFAULT NULL,
                license VARCHAR(64) DEFAULT NULL,
                width INT DEFAULT NULL,
                height INT DEFAULT NULL,
                storage_bucket VARCHAR(63) DEFAULT NULL,
                storage_prefix VARCHAR(120) DEFAULT NULL,
                attempts SMALLINT DEFAULT 0 NOT NULL,
                failed_reason VARCHAR(64) DEFAULT NULL,
                requested_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                ready_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX commons_photo_file_key ON commons_photo (file)');
        $this->addSql('CREATE INDEX commons_photo_state_idx ON commons_photo (state)');
        $this->addSql("COMMENT ON COLUMN commons_photo.state IS 'pending|ready|unusable|failed — App\\Media\\Commons\\CommonsPhotoState. unusable is a verdict about the file and is terminal; failed is about us and may be retried.'");
        $this->addSql("COMMENT ON COLUMN commons_photo.storage_bucket IS 'Full bucket name, verbatim, the same one-key rule rider photos follow (photo-uploads.md §2).'");

        $this->addSql(<<<'SQL'
            CREATE TABLE wikidata_image (
                qid VARCHAR(24) NOT NULL PRIMARY KEY,
                file VARCHAR(240) DEFAULT NULL,
                answered BOOLEAN DEFAULT FALSE NOT NULL,
                checked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql("COMMENT ON COLUMN wikidata_image.answered IS 'TRUE once Wikidata has actually replied. answered + file IS NULL means asked, and there is no P18 — a cached answer, not a gap.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE wikidata_image');
        $this->addSql('DROP TABLE commons_photo');
    }
}
