<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Traffic;

use App\Traffic\TrafficCipher;
use App\Traffic\TrafficKeys;
use PHPUnit\Framework\TestCase;

/**
 * Keys and encryption for traffic data (docs/specs/traffic-measurements.md §4.2).
 */
final class TrafficCipherTest extends TestCase
{
    private const string SECRET = 'nuBwuANqdfd0r2fBj9Cs+698Zv9KUtJSd6PQH0mhmUo=';

    private function keys(string $secret = self::SECRET, string $env = 'test'): TrafficKeys
    {
        return new TrafficKeys($secret, $env);
    }

    public function testSealThenOpenGivesTheDataBack(): void
    {
        $cipher = new TrafficCipher($this->keys());
        $data = ['way' => 4521877, 'slot' => 73, 'bins' => [0, 1, 2]];

        self::assertSame($data, $cipher->open($cipher->seal($data)));
    }

    public function testTwoSealsOfTheSameDataDiffer(): void
    {
        $cipher = new TrafficCipher($this->keys());

        self::assertNotSame($cipher->seal(['a' => 1]), $cipher->seal(['a' => 1]));
    }

    public function testATamperedBlobIsRefused(): void
    {
        $cipher = new TrafficCipher($this->keys());
        $blob = $cipher->seal(['a' => 1]);
        $blob[\strlen($blob) - 1] = \chr(\ord($blob[\strlen($blob) - 1]) ^ 1);

        $this->expectException(\RuntimeException::class);
        $cipher->open($blob);
    }

    public function testAnotherSecretCannotOpenIt(): void
    {
        $blob = new TrafficCipher($this->keys())->seal(['a' => 1]);

        $this->expectException(\RuntimeException::class);
        new TrafficCipher($this->keys(base64_encode(random_bytes(32))))->open($blob);
    }

    public function testABlockKeyNamesOneRoadDirectionAndTimeBlockAcrossQuarters(): void
    {
        $k = $this->keys();
        $line = ['way' => 7, 'dir' => 'f', 'band' => 1, 'dayType' => 'workday', 'quarter' => '2026-Q4', 'label' => 'r'];

        self::assertSame(32, \strlen($k->blockKey($line)));
        self::assertSame($k->blockKey($line), $k->blockKey(['quarter' => '2026-Q3'] + $line), 'quarters wait together');
        self::assertNotSame($k->blockKey($line), $k->blockKey(['band' => 2] + $line), 'one block per part of the day');
        self::assertNotSame($k->blockKey($line), $k->blockKey(['dir' => 'b'] + $line));
        self::assertNotSame($k->blockKey($line), $this->keys(base64_encode(random_bytes(32)))->blockKey($line), 'keyed with the traffic secret');
        self::assertNotSame($k->seenCode(str_repeat('a', 64)), $k->seenCode(str_repeat('b', 64)));
        foreach (['bucketKey', 'voice', 'dayCode', 'riderKey'] as $gone) {
            self::assertFalse(method_exists($k, $gone), "{$gone}: nothing keys a rider or a stored total");
        }
    }

    public function testAMissingSecretStopsInsteadOfFallingBack(): void
    {
        $this->expectException(\LogicException::class);
        $this->keys('')->seenCode(str_repeat('a', 64));
    }

    public function testProductionRefusesTheCommittedDevelopmentAndTestKeys(): void
    {
        foreach (['prod', 'staging'] as $env) {
            foreach ([TrafficKeys::DEV_SECRET, TrafficKeys::TEST_SECRET] as $known) {
                try {
                    $this->keys($known, $env)->seenCode(str_repeat('a', 64));
                    self::fail("{$env} accepted a committed key");
                } catch (\LogicException) {
                }
            }
        }
        self::assertSame(32, \strlen($this->keys(TrafficKeys::DEV_SECRET, 'dev')->seenCode(str_repeat('a', 64))));
    }

    public function testABlobOpensOnlyForTheRowItWasSealedFor(): void
    {
        $cipher = new TrafficCipher($this->keys());
        $blob = $cipher->seal(['a' => 1], 'traffic_cell|row-1');

        self::assertSame(['a' => 1], $cipher->open($blob, 'traffic_cell|row-1'));
        $this->expectException(\RuntimeException::class);
        $cipher->open($blob, 'traffic_cell|row-2');
    }

    public function testPayloadSizesComeInSteps(): void
    {
        $cipher = new TrafficCipher($this->keys());
        $small = $cipher->seal(['a' => 1]);
        $larger = $cipher->seal(['a' => 1, 'b' => str_repeat('x', 100)]);

        self::assertSame(\strlen($small), \strlen($larger), 'a size says little about what is inside');
        self::assertSame(0, (\strlen($small) - 28) % TrafficCipher::PAD_STEP);
    }
}
