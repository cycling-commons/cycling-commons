<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\ItemType;
use App\Contribution\SubmissionDraft;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SubmissionDraftValidationTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
    }

    private function draft(string $title = 'Fontaine du Perron', array $attrs = []): SubmissionDraft
    {
        return new SubmissionDraft(
            type: ItemType::WaterFood,
            title: $title,
            lat: 50.64,
            lng: 5.57,
            attributes: $attrs,
        );
    }

    public function testValidDraftPasses(): void
    {
        self::assertCount(0, $this->validator->validate($this->draft()));
    }

    public function testAccentedLatinTitlePasses(): void
    {
        self::assertCount(0, $this->validator->validate($this->draft('Côte de la Redoute — Aywaille')));
    }

    public function testInvisibleCharacterInTitleIsRejected(): void
    {
        $violations = $this->validator->validate($this->draft("Fontaine\u{200B}du Perron")); // zero-width space
        self::assertGreaterThan(0, \count($violations));
    }

    public function testBlankTitleRejected(): void
    {
        self::assertGreaterThan(0, \count($this->validator->validate($this->draft(''))));
    }

    public function testOutOfRangeLatitudeRejected(): void
    {
        $draft = new SubmissionDraft(type: ItemType::WaterFood, title: 'X', lat: 91.0, lng: 0.0, attributes: []);
        self::assertGreaterThan(0, \count($this->validator->validate($draft)));
    }

    public function testUnknownAttributeKeyRejected(): void
    {
        $violations = $this->validator->validate($this->draft(attrs: ['definitely_not_a_field' => 'x']));
        self::assertGreaterThan(0, \count($violations));
    }
}
