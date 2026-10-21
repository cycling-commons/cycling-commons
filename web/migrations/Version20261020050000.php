<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Which text a town card translation was made from
 * (moderation-and-contribution.md §3.1e): the original's language and the
 * moment that original was written, and the submission that wrote it. A region's two texts keep the same in
 * their JSON entries (`basedOn`), so only the town table needs columns.
 */
final class Version20261020050000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'town_summary.based_on_lang/based_on_at: what a town text translates';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE town_summary ADD based_on_lang VARCHAR(2) DEFAULT NULL, ADD based_on_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ADD based_on_submission BIGINT DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN town_summary.based_on_lang IS 'The language of the local text this one translates; NULL when it is not a translation.'");
        $this->addSql("COMMENT ON COLUMN town_summary.based_on_at IS 'When that original was written (its edited_at then): a later edited_at means the original changed since.'");
        $this->addSql("COMMENT ON COLUMN town_summary.based_on_submission IS 'The submission that wrote that original (its submission_id then); two changes in one second differ by it.'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE town_summary DROP based_on_lang, DROP based_on_at, DROP based_on_submission');
    }
}
