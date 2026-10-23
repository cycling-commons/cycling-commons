<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `forecast_usage`: what the planner's weather costs, one row per minute with
 * any ask (App\Weather\ForecastUsage): the asks that reached our forecast,
 * the ones our cache answered, the requests sent to Open-Meteo and what they
 * count as there, the failed ones, the ones Open-Meteo refused, and the asks
 * our own limit refused. Counts only: no point, no date, no rider. The
 * admin's Weather usage page reads it against Open-Meteo's free tier.
 */
final class Version20261023030000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'forecast_usage: per-minute counts of the planner forecast asks, cache answers and Open-Meteo calls';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE forecast_usage (
                minute TIMESTAMP(0) WITH TIME ZONE NOT NULL PRIMARY KEY,
                asked INT NOT NULL DEFAULT 0,
                hits INT NOT NULL DEFAULT 0,
                calls INT NOT NULL DEFAULT 0,
                weight NUMERIC(10, 2) NOT NULL DEFAULT 0,
                failures INT NOT NULL DEFAULT 0,
                refused INT NOT NULL DEFAULT 0,
                limited INT NOT NULL DEFAULT 0
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE forecast_usage');
    }
}
