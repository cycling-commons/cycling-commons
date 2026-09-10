<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Account\DateFormat;
use App\Account\TimeFormat;
use App\Entity\User;
use App\Twig\DateDisplayExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Dates written the way the reader asked (docs/specs/account-and-auth.md §9).
 *
 * Built directly rather than pulled from the container: the whole behaviour is
 * a function of two inputs — who is signed in, and what language the page is in
 * — and constructing those explicitly is what makes each case readable.
 */
final class DateDisplayExtensionTest extends TestCase
{
    private const string WHEN = '2026-08-01 14:30:00';

    private function extension(
        ?DateFormat $format,
        string $locale = 'en',
        TimeFormat $time = TimeFormat::Auto,
    ): DateDisplayExtension {
        // A stub, not a mock: nothing here verifies HOW Security is called,
        // only what it hands back, and phpunit.dist.xml fails the run on the
        // notice a expectation-less mock raises.
        $security = $this->createStub(Security::class);
        if (null === $format) {
            $security->method('getUser')->willReturn(null);
        } else {
            $security->method('getUser')->willReturn((new User())->setDateFormat($format)->setTimeFormat($time));
        }

        $request = new Request();
        $request->setLocale($locale);
        $stack = new RequestStack();
        $stack->push($request);

        return new DateDisplayExtension($security, $stack);
    }

    public function testEachFormatWritesTheDateItPromises(): void
    {
        self::assertSame('2026-08-01', $this->extension(DateFormat::Ymd)->date(self::WHEN));
        self::assertSame('01-08-2026', $this->extension(DateFormat::Dmy)->date(self::WHEN));
        self::assertSame('08/01/2026', $this->extension(DateFormat::Mdy)->date(self::WHEN));
    }

    /**
     * The fixed formats are fixed. A Dutch rider who asked for 01-08-2026 gets
     * exactly that on a German page — the pattern is theirs, only month NAMES
     * belong to the page's language.
     */
    public function testAFixedFormatIgnoresThePageLanguage(): void
    {
        foreach (['en', 'fr', 'nl', 'de', 'es'] as $locale) {
            self::assertSame('01-08-2026', $this->extension(DateFormat::Dmy, $locale)->date(self::WHEN), $locale);
        }
    }

    public function testTheWrittenOutFormatUsesThePagesLanguage(): void
    {
        self::assertStringContainsString('August', $this->extension(DateFormat::Long, 'en')->date(self::WHEN));
        self::assertStringContainsString('augustus', $this->extension(DateFormat::Long, 'nl')->date(self::WHEN));
        self::assertStringContainsString('août', $this->extension(DateFormat::Long, 'fr')->date(self::WHEN));
    }

    /**
     * The complaint this feature exists for: a Dutch reader should not be
     * handed 2026-08-01 by default.
     */
    public function testAutoFollowsTheLanguageAndIsNotIso(): void
    {
        $dutch = $this->extension(DateFormat::Auto, 'nl')->date(self::WHEN);

        self::assertNotSame('2026-08-01', $dutch);
        self::assertStringStartsWith('1', $dutch, 'day first, as Dutch is written');
    }

    public function testSomebodyNotSignedInGetsTheLocalesOwnForm(): void
    {
        self::assertSame(
            $this->extension(DateFormat::Auto, 'nl')->date(self::WHEN),
            $this->extension(null, 'nl')->date(self::WHEN),
        );
    }

    public function testTheClockIsItsOwnChoiceNotOneInferredFromTheDate(): void
    {
        // The pairing that an earlier design made impossible: a day-first date
        // with a twelve-hour clock, and a month-first date with a 24-hour one.
        // Both are ordinary combinations and neither is derivable from the other.
        self::assertSame('01-08-2026 2:30 PM', $this->extension(DateFormat::Dmy, time: TimeFormat::H12)->dateTime(self::WHEN));
        self::assertSame('08/01/2026 14:30', $this->extension(DateFormat::Mdy, time: TimeFormat::H24)->dateTime(self::WHEN));
    }

    public function testAnAutoClockFollowsThePageLanguage(): void
    {
        // English says 2:30 PM, Dutch and German say 14:30 — which is exactly
        // what "match my language" should mean, and why it is the default.
        self::assertStringContainsString('2:30', $this->extension(DateFormat::Dmy, 'en')->dateTime(self::WHEN));
        self::assertStringContainsString('14:30', $this->extension(DateFormat::Dmy, 'nl')->dateTime(self::WHEN));
    }

    public function testBothFollowingTheLocaleUsesTheLocalesOwnJoin(): void
    {
        // One formatter rather than two halves glued with a space, so the
        // language supplies its own connector where it has one.
        $rendered = $this->extension(DateFormat::Auto, 'en')->dateTime(self::WHEN);

        self::assertStringContainsString('2026', $rendered);
        self::assertStringContainsString('2:30', $rendered);
    }

    public function testMonthIsAlwaysWrittenOutRatherThanNumeric(): void
    {
        // Even for a rider who chose an all-numeric date format: "08/2026" is
        // not something anyone says, and this string is read as prose.
        self::assertStringContainsString('August', $this->extension(DateFormat::Ymd, 'en')->month(self::WHEN));
        self::assertStringContainsString('2026', $this->extension(DateFormat::Ymd, 'en')->month(self::WHEN));
    }

    public function testNothingAndNonsenseRenderAsEmptyRatherThanBreakingThePage(): void
    {
        $ext = $this->extension(DateFormat::Ymd);

        self::assertSame('', $ext->date(null));
        self::assertSame('', $ext->date(''));
        self::assertSame('', $ext->date('not a date at all'));
        self::assertSame('', $ext->dateTime(null));
        self::assertSame('', $ext->month(null));
    }

    public function testAnUnknownStoredValueFallsBackToAutoInsteadOfFatalling(): void
    {
        $user = new User();
        $reflected = new \ReflectionProperty(User::class, 'dateFormat');
        $reflected->setValue($user, 'a-format-we-removed');

        self::assertSame(DateFormat::Auto, $user->getDateFormat());
    }
}
