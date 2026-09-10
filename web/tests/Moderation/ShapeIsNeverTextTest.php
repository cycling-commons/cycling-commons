<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Moderation;

use PHPUnit\Framework\TestCase;

/**
 * Geometry is reviewed on the map, never as text.
 *
 * `SubmissionQueue` builds the same diff twice: `changeRows()` for the drawer's
 * per-field list, and `diffStrings()` for the one-line summary. Both must skip
 * the shape fields, because the drawer already draws them in the Before/After
 * switch and a coordinate list is not something a curator can read.
 *
 * They drifted. `changeRows()` skipped them; `diffStrings()` carried a comment
 * saying it did and looped over every field anyway. The result only showed up
 * on the one submission where it matters most: an edit that changed ONLY the
 * shape has nothing in the per-field list, so the drawer fell through to the
 * summary and printed sixty coordinate pairs twice, directly above the map
 * switch showing the same change properly (owner-reported 2026-08-31).
 *
 * This is a source-level check rather than a behavioural one, deliberately.
 * Both methods are private, and the thing that broke was not a wrong output
 * from either: it was two places holding one rule, with only one of them
 * enforcing it. That is what is pinned here.
 */
final class ShapeIsNeverTextTest extends TestCase
{
    private const string SOURCE = __DIR__.'/../../src/Moderation/SubmissionQueue.php';

    /** @return array{0: string, 1: string} the changeRows and diffStrings bodies */
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
            $slice('private function diffStrings'),
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
        [$changeRows, $diffStrings] = $this->bodies();

        foreach (['changeRows' => $changeRows, 'diffStrings' => $diffStrings] as $name => $body) {
            self::assertStringContainsString(
                'self::SHAPE_FIELDS',
                $body,
                "{$name}() must skip the shape fields, not merely say that it does",
            );
            self::assertMatchesRegularExpression(
                '/in_array\(\$field, self::SHAPE_FIELDS, true\)\)\s*\{\s*continue;/',
                $body,
                "{$name}() must `continue` on a shape field",
            );
        }
    }
}
