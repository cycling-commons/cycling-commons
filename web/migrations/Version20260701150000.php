<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add users.locale — the user's preferred UI language (en|fr|nl|de), nullable.
 */
final class Version20260701150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add nullable users.locale (preferred UI language)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD locale VARCHAR(5) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP locale');
    }
}
