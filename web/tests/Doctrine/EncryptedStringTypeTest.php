<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\EncryptedStringType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;

/**
 * #14: a decrypt/structural failure must NOT throw during hydration — the type
 * is mapped on User::$totpSecret, so throwing would 500 every login/admin load
 * of the affected row. It hydrates as null instead, so the seed reads as absent
 * and the user can re-enrol.
 */
final class EncryptedStringTypeTest extends TestCase
{
    private EncryptedStringType $type;
    private AbstractPlatform $platform;

    #[\Override]
    protected function setUp(): void
    {
        $_SERVER['APP_SECRET'] = 'test-secret-for-encrypted-type';
        $this->type = new EncryptedStringType();
        // A concrete platform (the convert methods don't touch it) — avoids a
        // mock-of-abstract-class notice while keeping the type contract honest.
        $this->platform = new PostgreSQLPlatform();
    }

    public function testRoundTripsAValue(): void
    {
        $cipher = $this->type->convertToDatabaseValue('JBSWY3DPEHPK3PXP', $this->platform);
        self::assertIsString($cipher);
        self::assertSame('JBSWY3DPEHPK3PXP', $this->type->convertToPHPValue($cipher, $this->platform));
    }

    public function testCorruptedCiphertextHydratesAsNullNotThrow(): void
    {
        // Valid base64 + length but not authentic GCM (simulates key rotation).
        $garbage = base64_encode(random_bytes(64));
        self::assertNull($this->type->convertToPHPValue($garbage, $this->platform));
    }

    public function testStructurallyInvalidValueHydratesAsNull(): void
    {
        self::assertNull($this->type->convertToPHPValue('!!!not-base64!!!', $this->platform));
        self::assertNull($this->type->convertToPHPValue('c2hvcnQ=', $this->platform)); // too short
    }

    public function testNullAndEmptyStayNull(): void
    {
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
        self::assertNull($this->type->convertToPHPValue('', $this->platform));
    }
}
