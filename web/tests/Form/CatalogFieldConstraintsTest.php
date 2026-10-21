<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Form;

use App\Catalog\CatalogField;
use App\Form\CatalogFieldConstraints;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Url;

/**
 * User-editable URL attributes (the
 * stay's `web` / `bookingLink`) were plain text fields, so `javascript:…` and
 * other non-http schemes passed validation, persisted, and were interpolated
 * into an `<a href>` on the public map (stored XSS). A url-kind field must emit
 * a scheme-restricted {@see Url} constraint so only http/https survive.
 */
final class CatalogFieldConstraintsTest extends TestCase
{
    /** @return list<Constraint> */
    private static function constraintsOf(CatalogField $field): array
    {
        return CatalogFieldConstraints::for($field);
    }

    public function testUrlFieldEmitsUrlConstraintRestrictedToHttpAndHttps(): void
    {
        $url = null;
        foreach (self::constraintsOf(CatalogField::url('web', 'Website')) as $c) {
            if ($c instanceof Url) {
                $url = $c;
            }
        }

        self::assertNotNull($url, 'a url-kind field must emit a Url constraint');
        self::assertSame(
            ['http', 'https'],
            $url->protocols,
            'only http/https may pass — javascript:, data:, vbscript: etc. must be rejected',
        );
    }

    public function testUrlFieldStillKeepsTheTextLikeLengthGuard(): void
    {
        $hasLength = false;
        foreach (self::constraintsOf(CatalogField::url('web', 'Website')) as $c) {
            $hasLength = $hasLength || $c instanceof Length;
        }

        self::assertTrue($hasLength, 'url fields remain text-like — Length still applies');
    }

    public function testPlainTextFieldNeverGetsAUrlConstraint(): void
    {
        foreach (self::constraintsOf(CatalogField::text('note', 'Note')) as $c) {
            self::assertNotInstanceOf(Url::class, $c);
        }
    }

    /** The year of a cycling story (cycling-history.md §1): digits only, or the timeline cannot order it. */
    public function testAYearFieldTakesOnlyThreeOrFourDigits(): void
    {
        $validator = \Symfony\Component\Validator\Validation::createValidator();
        $constraints = self::constraintsOf(CatalogField::year('year', 'Year of the story'));

        foreach (['1903', '875'] as $ok) {
            self::assertCount(0, $validator->validate($ok, $constraints), $ok);
        }
        foreach (['about 1900', '19th century', '12345', '1903 '] as $bad) {
            self::assertGreaterThan(0, \count($validator->validate($bad, $constraints)), $bad);
        }
    }
}
