<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Every stay type is one OSM `tourism` tag (docs/specs/osm-data-architecture.md §5a):
 * a B&B is a guest house, a gîte a chalet, a budget stay a hostel. Rows an
 * earlier reading typed `bnb`, `gite` or `budget` take the OSM type.
 */
final class Version20261008100000 extends AbstractMigration
{
    private const array MERGE = ['bnb' => 'guest_house', 'gite' => 'chalet', 'budget' => 'hostel'];

    #[\Override]
    public function getDescription(): string
    {
        return 'Stay types: B&B into guest house, gîte into chalet, budget stay into hostel';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (self::MERGE as $from => $to) {
            $this->addSql(
                "UPDATE item SET attributes = jsonb_set(attributes, '{type}', to_jsonb(CAST(:to AS text)))
                  WHERE letter = 'O' AND attributes->>'type' = :from",
                ['from' => $from, 'to' => $to],
            );
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        // The merge is many to one: the earlier types cannot be told apart again.
    }
}
