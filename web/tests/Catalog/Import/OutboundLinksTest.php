<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Catalog\Import;

use App\Catalog\Import\OutboundLinks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The outbound-links shape gate: two levels (destinations, then language
 * variants), https only, and caps that ARE the anti-spam design - an item
 * with thirty links is an advert, and the same host may not fill the list.
 */
final class OutboundLinksTest extends TestCase
{
    public function testTheCanonicalShapePasses(): void
    {
        OutboundLinks::assertValid([
            ['label' => 'Official site', 'urls' => [['url' => 'https://muiderslot.nl']]],
            ['label' => 'Wikipedia', 'urls' => [
                ['url' => 'https://en.wikipedia.org/wiki/Muiderslot', 'locale' => 'en'],
                ['url' => 'https://nl.wikipedia.org/wiki/Muiderslot', 'locale' => 'nl'],
            ]],
        ]);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalid(): iterable
    {
        yield 'not a list' => [['a' => 1], 'must be a list'];
        yield 'too many destinations' => [
            array_map(static fn (int $i): array => ['urls' => [['url' => "https://site$i.example"]]], range(1, 5)),
            'at most 4 destinations per item',
        ];
        yield 'http is not https' => [[['urls' => [['url' => 'http://example.com']]]], 'https:// urls only'];
        yield 'javascript payload' => [[['urls' => [['url' => 'javascript:alert(1)']]]], 'https:// urls only'];
        yield 'empty urls' => [[['urls' => []]], 'between 1 and'];
        yield 'stray keys' => [[['urls' => [['url' => 'https://example.com']], 'seo' => 'x']], 'only label and urls'];
        yield 'unknown locale' => [[['urls' => [['url' => 'https://example.com', 'locale' => 'xx']]]], 'unknown locale'];
        yield 'same host flooding the list' => [
            [
                ['urls' => [['url' => 'https://spam.example/a']]],
                ['urls' => [['url' => 'https://www.spam.example/b']]],
                ['urls' => [['url' => 'https://spam.example/c']]],
            ],
            'at most 2 destinations from spam.example',
        ];
    }

    #[DataProvider('invalid')]
    public function testViolationsAreNamed(mixed $links, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        OutboundLinks::assertValid($links);
    }
}
