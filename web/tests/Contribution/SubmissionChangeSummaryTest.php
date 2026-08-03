<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Catalog\CatalogFormRegistry;
use App\Catalog\Entity\Submission;
use App\Contribution\SubmissionChangeSummary;
use PHPUnit\Framework\TestCase;

/**
 * What a rider is shown about their own contribution.
 *
 * The point of the class is that a rider never has to read a field KEY, so the
 * label resolution is what these tests actually guard.
 */
final class SubmissionChangeSummaryTest extends TestCase
{
    private function summary(): SubmissionChangeSummary
    {
        return new SubmissionChangeSummary(new CatalogFormRegistry());
    }

    /** @param array<string, mixed> $changes */
    private function submission(string $letter, array $changes): Submission
    {
        $sub = new Submission();
        $sub->setLetter($letter);
        $sub->setChanges($changes);

        return $sub;
    }

    public function testAFieldIsNamedTheWayTheFormAskedForIt(): void
    {
        $rows = $this->summary()->rows($this->submission('B', [
            'sq' => ['was' => 'Smooth', 'now' => 'Rough'],
        ]));

        self::assertSame([
            ['label' => 'Road quality', 'was' => 'Smooth', 'now' => 'Rough'],
        ], $rows, 'a rider reads "Road quality", never the sq key');
    }

    public function testAddingAMissingFieldHasNothingToStrikeOut(): void
    {
        $rows = $this->summary()->rows($this->submission('B', [
            'maxGradient' => ['was' => null, 'now' => '17'],
        ]));

        self::assertNull($rows[0]['was'], 'no previous value means no struck-out line');
        self::assertSame('17', $rows[0]['now']);
        self::assertSame('Max gradient (%)', $rows[0]['label']);
    }

    /**
     * An empty string is "there was nothing here", not "the previous value was
     * the empty string" — rendering a struck-out blank teaches a rider nothing.
     */
    public function testAnEmptyPreviousValueCountsAsNoPreviousValue(): void
    {
        $rows = $this->summary()->rows($this->submission('C', [
            'note' => ['was' => '', 'now' => 'Frost-shut in winter'],
        ]));

        self::assertNull($rows[0]['was']);
    }

    public function testAMultiSelectReadsAsAListAndNotAsJson(): void
    {
        $rows = $this->summary()->rows($this->submission('E', [
            'accessibility' => ['was' => null, 'now' => ['Step-free', 'Handbike-friendly']],
        ]));

        self::assertSame('Step-free, Handbike-friendly', $rows[0]['now']);
    }

    /**
     * A field the registry no longer carries keeps its key rather than
     * vanishing. Showing `hairpinsOld` is honest; showing nothing would hide
     * part of what somebody actually submitted.
     */
    public function testAnUnknownFieldKeepsItsKey(): void
    {
        $rows = $this->summary()->rows($this->submission('B', [
            'hairpinsOld' => ['was' => null, 'now' => '3'],
        ]));

        self::assertSame('hairpinsOld', $rows[0]['label']);
    }

    /** Letter K (routes) has no registry field set; every row falls back. */
    public function testALetterWithNoRegistryEntryFallsBackToKeys(): void
    {
        $rows = $this->summary()->rows($this->submission('K', [
            'name' => ['was' => 'Old', 'now' => 'New'],
        ]));

        self::assertSame('name', $rows[0]['label']);
    }

    public function testAPayloadThatIsNotADiffIsSkippedRatherThanGuessedAt(): void
    {
        $rows = $this->summary()->rows($this->submission('B', [
            'sq' => 'Rough',                                  // not a {was, now} pair
            'tr' => ['was' => 'Quiet', 'now' => 'Busy'],
        ]));

        self::assertCount(1, $rows);
        self::assertSame('Traffic', $rows[0]['label']);
    }

    public function testNoChangesYieldsNoRows(): void
    {
        self::assertSame([], $this->summary()->rows($this->submission('B', [])));
    }
}
