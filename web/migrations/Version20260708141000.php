<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Catalog\BikeTypeVocabulary;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260708141000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'recommended_route.attributes.bikeTypes — collapse to a canonical list<string>, folding legacy handbike (P2-D2)';
    }

    public function up(Schema $schema): void
    {
        // One canonical bikeTypes shape (route-domain spec §12 P2-D2, D6): a
        // list of BikeType values. Folds the retired single-string/'Any'
        // shapes and the separate legacy `handbike` attribute into the list,
        // then drops the legacy key — mirrors Version20260708140000's
        // per-row read/normalize/write pattern for difficulty.
        // (attributes -> key) IS NOT NULL, not the jsonb `?` "has key" operator —
        // `?` collides with PDO/DBAL positional placeholders even in a
        // parameter-free statement.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, attributes FROM recommended_route WHERE attributes -> 'bikeTypes' IS NOT NULL OR attributes -> 'handbike' IS NOT NULL",
        );

        foreach ($rows as $row) {
            /** @var array<string, mixed> $attrs */
            $attrs = json_decode((string) $row['attributes'], true, 512, \JSON_THROW_ON_ERROR);

            $bikeTypes = BikeTypeVocabulary::normalize($attrs['bikeTypes'] ?? null, $attrs['handbike'] ?? null);
            unset($attrs['handbike']);
            if ([] !== $bikeTypes) {
                $attrs['bikeTypes'] = $bikeTypes;
            } else {
                unset($attrs['bikeTypes']);
            }

            $this->connection->executeStatement(
                'UPDATE recommended_route SET attributes = :attrs::jsonb WHERE id = :id',
                [
                    'attrs' => json_encode($attrs, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
                    'id' => $row['id'],
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // One-way normalization; no down-migration (bikeTypes stays canonical).
    }
}
