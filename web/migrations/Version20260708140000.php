<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'recommended_route.attributes.difficulty — normalize legacy string values to canonical {score,label} (P2-D1)';
    }

    public function up(Schema $schema): void
    {
        // Rewrite string difficulty → {score,label} on the 5-level canonical scale
        // (route-domain spec §12 P2-D1). Covers both the canonical labels and the
        // legacy rider vocab (Gentle/Moderate/Hard/Very hard).
        $map = ['Gentle' => 1, 'Easy' => 1, 'Moderate' => 2, 'Challenging' => 3, 'Hard' => 4, 'Very hard' => 5];
        $labels = [1 => 'Easy', 2 => 'Moderate', 3 => 'Challenging', 4 => 'Hard', 5 => 'Very hard'];
        foreach ($map as $legacy => $score) {
            $obj = json_encode(['score' => $score, 'label' => $labels[$score]], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
            $this->connection->executeStatement(
                "UPDATE recommended_route SET attributes = jsonb_set(attributes, '{difficulty}', :obj::jsonb)
                 WHERE attributes->>'difficulty' = :legacy",
                ['obj' => $obj, 'legacy' => $legacy],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // One-way normalization; no down-migration (difficulty stays canonical).
    }
}
