<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Validator\PlainDisplayName;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Display names are labels, not identifiers (account-and-auth.md §9) — two
 * riders may genuinely share one. What they may NOT contain is exotic spacing
 * or compatibility forms: a name renders in photo credits, on public rider
 * profiles and in admin lists, and those surfaces should show what the rider
 * actually typed rather than something that merely looks like it.
 *
 * NoSuspiciousCharacters already covers mixed-script confusables and the
 * `\p{Cf}` guard covers invisibles. This constraint covers the third class
 * neither of them touches: Unicode whitespace that is not a plain space, runs
 * of spaces, edge whitespace, and Unicode compatibility forms (fullwidth and
 * friends), the last of which cannot be expressed as a regular expression.
 */
final class PlainDisplayNameValidatorTest extends TestCase
{
    private ValidatorInterface $validator;

    #[\Override]
    protected function setUp(): void
    {
        $this->validator = Validation::createValidator();
    }

    /** @return list<string> */
    private function messages(?string $value): array
    {
        $violations = $this->validator->validate($value, new PlainDisplayName());

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }

    /**
     * Real names must keep working — accents, apostrophes, hyphens, and
     * non-Latin scripts are all ordinary, not suspicious.
     *
     * @return iterable<string, array{string}>
     */
    public static function acceptedNames(): iterable
    {
        yield 'plain ascii' => ['John Doe'];
        yield 'three words' => ['Anne Sophie de Vries'];
        yield 'accented' => ['José Müller'];
        yield 'nordic' => ['Élise Østergård'];
        yield 'apostrophe' => ["Jean-Luc D'Arcy"];
        yield 'cjk' => ['大山 太郎'];
        yield 'single word' => ['Xander'];
        yield 'digits' => ['Rider 42'];
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedNames(): iterable
    {
        yield 'double space' => ["John  Doe"];
        yield 'triple space' => ["John   Doe"];
        yield 'non-breaking space' => ["John\u{00A0}Doe"];
        yield 'trailing non-breaking space' => ["John Doe\u{00A0}"];
        yield 'ideographic space' => ["John\u{3000}Doe"];
        yield 'en quad' => ["John\u{2000}Doe"];
        yield 'narrow no-break space' => ["John\u{202F}Doe"];
        yield 'tab' => ["John\tDoe"];
        yield 'newline' => ["John\nDoe"];
        yield 'leading space' => [' John Doe'];
        yield 'trailing space' => ['John Doe '];
        yield 'fullwidth latin' => ["\u{FF2A}\u{FF4F}\u{FF48}\u{FF4E}"];
        yield 'ligature compatibility form' => ["\u{FB01}rst Rider"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedNames')]
    public function testOrdinaryNamesAreAccepted(string $name): void
    {
        self::assertSame([], $this->messages($name), sprintf('"%s" is an ordinary name', $name));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedNames')]
    public function testExoticSpacingAndCompatibilityFormsAreRejected(string $name): void
    {
        self::assertNotSame([], $this->messages($name), sprintf('%s must be rejected', json_encode($name)));
    }

    /**
     * Emptiness is NotBlank's job. Several write paths legitimately persist a
     * User before a name exists (tests, partial flows), and this constraint
     * sits on the entity, so it must stay silent rather than break them.
     */
    public function testEmptyValuesAreSomebodyElsesProblem(): void
    {
        self::assertSame([], $this->messages(null));
        self::assertSame([], $this->messages(''));
    }

    public function testTheSpacingAndCompatibilityMessagesAreDistinct(): void
    {
        $spacing = $this->messages('John  Doe');
        $compatibility = $this->messages("\u{FF2A}\u{FF4F}\u{FF48}\u{FF4E}");

        self::assertSame(['form.error_display_name_spacing'], $spacing);
        self::assertSame(['form.error_display_name_compatibility'], $compatibility);
    }

    /** One violation per name, not one per offending character. */
    public function testAViolationIsReportedOnce(): void
    {
        self::assertCount(1, $this->messages("John  Doe  Smith"));
    }
}
