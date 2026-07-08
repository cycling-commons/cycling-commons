<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution\Gpx;

use App\Contribution\Gpx\GpxWriter;
use PHPUnit\Framework\TestCase;

/**
 * Route-domain spec §7: the writer serializes a user-influenced route name into
 * <name> element text. XMLWriter must XML-escape it so the output stays
 * well-formed and no name can inject markup.
 */
final class GpxWriterTest extends TestCase
{
    public function testNameIsXmlEscapedInElementText(): void
    {
        $gpx = (new GpxWriter())->write('A & B <x> "q"', [[5.2, 50.4]]);

        // & < > must be entity-escaped in element text; a raw '&' or '<x>'
        // would either break parsing or inject an element.
        self::assertStringContainsString('A &amp; B &lt;x&gt;', $gpx);
        self::assertStringNotContainsString('<x>', $gpx);
    }

    public function testOutputRemainsWellFormedWithHostileName(): void
    {
        $gpx = (new GpxWriter())->write('A & B <x> "q"', [[5.2, 50.4]]);

        $prev = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($gpx);
        } finally {
            libxml_use_internal_errors($prev);
        }

        self::assertNotFalse($xml, 'A hostile name must not produce malformed XML.');
    }
}
