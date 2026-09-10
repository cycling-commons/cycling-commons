<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Catalog\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * PostGIS geometry column ⇄ GeoJSON string. Spatial queries stay in raw SQL; this type only hydrates ORM.
 *
 * @api
 */
final class GeometryType extends Type
{
    public const string NAME = 'geometry';

    #[\Override]
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'geometry(Geometry, 4326)';
    }

    #[\Override]
    public function convertToDatabaseValueSQL(string $sqlExpr, AbstractPlatform $platform): string
    {
        return sprintf('ST_SetSRID(ST_GeomFromGeoJSON(%s), 4326)', $sqlExpr);
    }

    #[\Override]
    public function convertToPHPValueSQL(string $sqlExpr, AbstractPlatform $platform): string
    {
        return sprintf('ST_AsGeoJSON(%s)', $sqlExpr);
    }

    #[\Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return null === $value ? null : (string) $value;
    }

    #[\Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return null === $value ? null : (string) $value;
    }
}
