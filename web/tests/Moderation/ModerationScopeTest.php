<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Moderation;

use App\Moderation\ModerationScope;
use PHPUnit\Framework\TestCase;

final class ModerationScopeTest extends TestCase
{
    public function testGlobalScopeEmitsNoSql(): void
    {
        $frag = ModerationScope::global()->sqlFragment('s');

        self::assertSame('', $frag['sql']);
        self::assertSame([], $frag['params']);
    }

    public function testLimitedScopeEmitsPredicateWithBothLists(): void
    {
        $frag = ModerationScope::limited([3, 7], ['be'])->sqlFragment('s');

        self::assertStringContainsString('s.region_id IS NULL', $frag['sql']);
        self::assertStringContainsString('s.region_id IN (:sc_rids)', $frag['sql']);
        self::assertStringContainsString('country_code IN (:sc_ccs)', $frag['sql']);
        self::assertSame([3, 7], $frag['params']['sc_rids']);
        self::assertSame(['BE'], $frag['params']['sc_ccs'], 'country codes normalize to uppercase');
    }

    public function testEmptyListsBindImpossibleSentinels(): void
    {
        $frag = ModerationScope::limited([3], [])->sqlFragment('r');

        self::assertSame(['--'], $frag['params']['sc_ccs']);
        $frag2 = ModerationScope::limited([], ['BE'])->sqlFragment('r');
        self::assertSame([-1], $frag2['params']['sc_rids']);
    }
}
