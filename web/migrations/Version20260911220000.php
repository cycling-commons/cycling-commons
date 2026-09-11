<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The contributor halves merge on email, so the GitHub half must carry one.
 *
 * A GitHub login and a site account are different identity systems, and
 * counting them separately would double-count the curator who also ships
 * code. The fix is the email GitHub publishes on a user's profile: the sync
 * fetches it per login (a handful of calls a day, and only for rows that
 * still lack one) and stores it here, so CommunityProgress can merge the
 * two halves in PHP. A profile with no public email stays NULL and keeps
 * trying on later runs — at this scale, retrying daily is cheaper than
 * storing a "we looked" marker.
 */
final class Version20260911220000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'github_contributor gains the profile email the contributor count merges on';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE github_contributor ADD email VARCHAR(180) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE github_contributor DROP email');
    }
}
