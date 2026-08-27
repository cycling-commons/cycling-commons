<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\OrganisationIdentity;
use PHPUnit\Framework\TestCase;

/**
 * The legal identity block (docs/specs/contact-and-support.md §2).
 *
 * The behaviour worth pinning is the honest one: an unconfigured deployment
 * must report itself INCOMPLETE and name what is missing, rather than rendering
 * whatever happens to be set and looking finished. A block that looks like
 * compliance and is not is worse than an obviously empty one.
 */
final class OrganisationIdentityTest extends TestCase
{
    private function identity(array $overrides = []): OrganisationIdentity
    {
        $f = $overrides + [
            'name' => 'Example Foundation',
            'legalForm' => 'Stichting',
            'street' => 'Voorbeeldstraat 1',
            'postcode' => '1234 AB',
            'city' => 'Amsterdam',
            'country' => 'Netherlands',
            'registrationNumber' => '12345678',
            'vatNumber' => 'NL123456789B01',
            'contactEmail' => 'hello@example.test',
            'phone' => '',
        ];

        return new OrganisationIdentity(
            $f['name'], $f['legalForm'], $f['street'], $f['postcode'], $f['city'],
            $f['country'], $f['registrationNumber'], $f['vatNumber'], $f['contactEmail'], $f['phone'],
        );
    }

    public function testAFullyConfiguredIdentityIsComplete(): void
    {
        $id = $this->identity();

        self::assertTrue($id->isComplete());
        self::assertSame([], $id->missing());
        self::assertSame('Example Foundation', $id->name());
        self::assertSame('12345678', $id->registrationNumber());
        self::assertSame('NL123456789B01', $id->vatNumber());
    }

    public function testTheCommittedDefaultsAreEmptyAndSayWhatIsMissing(): void
    {
        $id = new OrganisationIdentity('', '', '', '', '', '', '', '', '', '');

        self::assertFalse($id->isComplete());
        self::assertNull($id->name());
        self::assertSame([], $id->addressLines());
        self::assertSame([
            'support.legal.field_name',
            'support.legal.field_address',
            'support.legal.field_registration',
            'support.legal.field_email',
        ], $id->missing());
    }

    /**
     * The VAT number is required only of an operator that is VAT-registered.
     * Counting it would make a foundation that is not permanently "incomplete".
     */
    public function testNoVatNumberIsStillComplete(): void
    {
        $id = $this->identity(['vatNumber' => '']);

        self::assertTrue($id->isComplete());
        self::assertNull($id->vatNumber());
    }

    public function testEachMissingRequiredFieldIsNamedOnItsOwn(): void
    {
        self::assertSame(['support.legal.field_name'], $this->identity(['name' => ''])->missing());
        self::assertSame(['support.legal.field_registration'], $this->identity(['registrationNumber' => ''])->missing());
        self::assertSame(['support.legal.field_email'], $this->identity(['contactEmail' => ''])->missing());
    }

    public function testAnAddressIsMissingOnlyWhenEveryLineIs(): void
    {
        self::assertSame(
            ['support.legal.field_address'],
            $this->identity(['street' => '', 'postcode' => '', 'city' => '', 'country' => ''])->missing(),
        );
        // One line left is still an address; the block renders what it has.
        self::assertSame([], $this->identity(['street' => '', 'postcode' => '', 'city' => ''])->missing());
    }

    public function testAddressLinesReadInPostalOrderAndDropBlanks(): void
    {
        self::assertSame(
            ['Voorbeeldstraat 1', '1234 AB Amsterdam', 'Netherlands'],
            $this->identity()->addressLines(),
        );
        self::assertSame(
            ['Voorbeeldstraat 1', 'Amsterdam'],
            $this->identity(['postcode' => '', 'country' => ''])->addressLines(),
        );
    }

    /** Whitespace-only configuration is the same as no configuration. */
    public function testWhitespaceCountsAsUnset(): void
    {
        $id = $this->identity(['name' => '   ', 'registrationNumber' => "\t"]);

        self::assertNull($id->name());
        self::assertFalse($id->isComplete());
    }

    public function testThePhoneIsOptionalEverywhere(): void
    {
        self::assertNull($this->identity()->phone());
        self::assertTrue($this->identity()->isComplete());
        self::assertSame('+31 20 000 0000', $this->identity(['phone' => '+31 20 000 0000'])->phone());
    }
}
