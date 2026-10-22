<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use PHPUnit\Framework\TestCase;

/**
 * Geometry is reviewed on the map, never as text.
 *
 * `SubmissionQueue` builds the same diff twice: `changeRows()` for the drawer's
 * per-field list, and `labelledChanges()` for the card's labelled rows. Both
 * must skip the shape fields, because the drawer already draws them in the
 * Before/After switch and a coordinate list is not something a curator can
 * read. When only one of the two skipped them, an edit that changed ONLY the
 * shape printed sixty coordinate pairs twice, directly above the map switch
 * showing the same change properly (owner-reported 2026-08-31).
 *
 * This is a source-level check rather than a behavioural one, deliberately.
 * Both methods are private, and the thing that broke was not a wrong output
 * from either: it was two places holding one rule, with only one of them
 * enforcing it. That is what is pinned here; SubmissionQueueTest pins the
 * card's output.
 */
final class ShapeIsNeverTextTest extends TestCase
{
    private const string SOURCE = __DIR__.'/../../src/Moderation/SubmissionQueue.php';

    /** @return array{0: string, 1: string} the changeRows and labelledChanges bodies */
    private function bodies(): array
    {
        $src = file_get_contents(self::SOURCE);
        self::assertIsString($src, 'SubmissionQueue.php must be readable');

        $slice = static function (string $after) use ($src): string {
            $at = strpos($src, $after);
            self::assertIsInt($at, "{$after} must still exist");

            return substr($src, $at, 2000);
        };

        return [
            $slice('private static function changeRows'),
            $slice('private function labelledChanges'),
        ];
    }

    public function testTheShapeFieldsAreNamedInOnePlace(): void
    {
        $src = (string) file_get_contents(self::SOURCE);
        self::assertMatchesRegularExpression(
            "/private const array SHAPE_FIELDS = \\[[^\\]]*'segment'[^\\]]*\\]/",
            $src,
            'a marked stretch is a shape; if this list stops naming it the drawer starts dumping coordinates',
        );
    }

    public function testBothDiffsSkipTheShapeFields(): void
    {
        [$changeRows, $labelledChanges] = $this->bodies();

        self::assertMatchesRegularExpression(
            '/in_array\(\$field, self::SHAPE_FIELDS, true\)\)\s*\{\s*continue;/',
            $changeRows,
            'changeRows() must `continue` on a shape field, not merely say that it does',
        );
        self::assertMatchesRegularExpression(
            '/foreach \(self::SHAPE_FIELDS as \$field\)\s*\{\s*unset\(\$changes\[\$field\]\);/',
            $labelledChanges,
            'labelledChanges() must drop every shape field before it builds the rows',
        );
    }
}
