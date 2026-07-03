<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * heat_point round-trips the season the ride-heat layer filters by (spec
 * §4.3): the fixture points are [lat, lng, season] but the column was missing.
 */
final class Version20260703210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add heat_point.season';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE heat_point ADD season VARCHAR(8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE heat_point DROP season');
    }
}
