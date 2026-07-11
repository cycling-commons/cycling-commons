<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260712120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'route_suggestion.segments — located correction stretches (route-domain spec §16 S4)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE route_suggestion ADD segments JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE route_suggestion DROP segments');
    }
}
