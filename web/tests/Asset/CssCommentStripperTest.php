<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Asset;

use App\Asset\CssCommentStripper;
use PHPUnit\Framework\TestCase;

/** Production stylesheets carry no comments; what they say otherwise stays exact. */
final class CssCommentStripperTest extends TestCase
{
    public function testCommentsAndTheBlankLinesTheyLeaveGo(): void
    {
        $css = "/* why the hero is this tall */\n.hero{min-height:100svh}\n\n  /* two\n     lines */\n.a{color:red} /* trailing */\n";

        self::assertSame(".hero{min-height:100svh}\n.a{color:red}\n", CssCommentStripper::strip($css));
    }

    public function testTheLicenceLineStays(): void
    {
        self::assertSame(
            "/* SPDX-License-Identifier: AGPL-3.0-only */\n.a{b:c}\n",
            CssCommentStripper::strip("/* SPDX-License-Identifier: AGPL-3.0-only */\n/* why */\n.a{b:c}"),
        );
    }

    public function testALicenceNoticeStays(): void
    {
        self::assertStringContainsString('/*! MIT licence */', CssCommentStripper::strip("/*! MIT licence */\n.a{b:c}"));
    }

    public function testTextInQuotesIsNeverTouched(): void
    {
        $css = ".a::before{content:\"/* not a comment */\"}\n.b{background:url('x/*y*/z.png')}\n.c{content:'it\\'s /* still text'}";

        self::assertSame($css."\n", CssCommentStripper::strip($css));
    }

    public function testAnUnclosedCommentEndsTheFile(): void
    {
        self::assertSame(".a{b:c}\n", CssCommentStripper::strip(".a{b:c}\n/* never closed"));
    }
}
