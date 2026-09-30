<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `recommended_route.revised_at`: when the proposer last changed a proposal
 * after sending it, NULL when they never did. The Routes desk card shows it,
 * so a curator reviewing a proposal knows the proposer changed it since.
 *
 * @see docs/specs/route-domain.md §4.6
 */
final class Version20260930160000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'recommended_route: revised_at, when the proposer last changed a waiting proposal';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recommended_route ADD revised_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recommended_route DROP revised_at');
    }
}
