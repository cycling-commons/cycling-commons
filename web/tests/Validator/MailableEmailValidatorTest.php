<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Validator\MailableEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * An address is valid when the mailer can address it: `new Address()`
 * accepts it.
 */
final class MailableEmailValidatorTest extends TestCase
{
    private function violations(?string $value): int
    {
        return \count(Validation::createValidator()->validate($value, new MailableEmail()));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'two dots in a row' => ['j..t.omr.i.c.h@gmail.com'];
        yield 'leading dot' => ['.rider@example.com'];
        yield 'trailing dot before the @' => ['rider.@example.com'];
    }

    #[DataProvider('refused')]
    public function testAnAddressTheMailerRefusesIsAViolation(string $address): void
    {
        self::assertSame(1, $this->violations($address));
    }

    public function testAnOrdinaryAddressPasses(): void
    {
        self::assertSame(0, $this->violations('j.t.omrich+cc@gmail.com'));
    }

    /** Blank is NotBlank's job, not this one's. */
    public function testEmptyIsLeftToNotBlank(): void
    {
        self::assertSame(0, $this->violations(''));
        self::assertSame(0, $this->violations(null));
    }
}
