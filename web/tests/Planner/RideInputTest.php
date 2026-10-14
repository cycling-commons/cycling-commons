<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Planner;

use App\Catalog\BikeType;
use App\Planner\RideInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The save body's shape (docs/specs/route-planner.md section 8), checked
 * before any budget is spent: a name of 1 to 80 characters, a known bike, 2
 * to 25 stops on Earth, a LineString of 2 to 5000 points, whole-number
 * figures. Anything else is refused as a whole.
 */
final class RideInputTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function body(): array
    {
        return [
            'name' => '  Sunday loop ',
            'bike' => 'Gravel',
            'stops' => [
                ['lat' => 50.4925, 'lng' => 5.8633, 'name' => 'Start', 'extra' => 'dropped'],
                ['lat' => 50.4745, 'lng' => 5.8627, 'item' => 'B:12', 'name' => 'Fontaine'],
            ],
            'line' => ['type' => 'LineString', 'coordinates' => [[5.8633, 50.4925], [5.8630, 50.4835], [5.8627, 50.4745]]],
            'distanceM' => 2001.6,
            'ascentM' => 120,
            'descentM' => 118.4,
        ];
    }

    public function testAValidBodyIsNormalised(): void
    {
        $input = RideInput::parse(self::body());

        self::assertNotNull($input);
        self::assertNull($input->id);
        self::assertSame('Sunday loop', $input->name);
        self::assertSame(BikeType::Gravel, $input->bike);
        self::assertSame([
            ['lat' => 50.4925, 'lng' => 5.8633, 'name' => 'Start'],
            ['lat' => 50.4745, 'lng' => 5.8627, 'item' => 'B:12', 'name' => 'Fontaine'],
        ], $input->stops);
        self::assertSame([[5.8633, 50.4925], [5.8630, 50.4835], [5.8627, 50.4745]], $input->line);
        self::assertSame(2002, $input->distanceM);
        self::assertSame(120, $input->ascentM);
        self::assertSame(118, $input->descentM);
        self::assertSame('{"type":"LineString","coordinates":[[5.8633,50.4925],[5.863,50.4835],[5.8627,50.4745]]}', $input->lineGeoJson());
    }

    public function testAnIdIsKeptWhenItIsAPositiveInteger(): void
    {
        $input = RideInput::parse(['id' => 42] + self::body());
        self::assertNotNull($input);
        self::assertSame(42, $input->id);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalid(): iterable
    {
        yield 'not an object' => ['[]'];
        yield 'an id of zero' => [['id' => 0] + self::body()];
        yield 'an id that is a string' => [['id' => '42'] + self::body()];
        yield 'no name' => [array_diff_key(self::body(), ['name' => 1])];
        yield 'a blank name' => [['name' => "\t "] + self::body()];
        yield 'a name of 81 characters' => [['name' => str_repeat('n', 81)] + self::body()];
        yield 'an unknown bike' => [['bike' => 'Unicycle'] + self::body()];
        yield 'a bike that is not a string' => [['bike' => 3] + self::body()];
        yield 'one stop' => [['stops' => [['lat' => 50.4925, 'lng' => 5.8633]]] + self::body()];
        yield '26 stops' => [['stops' => array_fill(0, 26, ['lat' => 50.4925, 'lng' => 5.8633])] + self::body()];
        yield 'a stop without a latitude' => [['stops' => [['lng' => 5.8633], ['lat' => 50.4745, 'lng' => 5.8627]]] + self::body()];
        yield 'a stop past the pole' => [['stops' => [['lat' => 90.5, 'lng' => 5.8633], ['lat' => 50.4745, 'lng' => 5.8627]]] + self::body()];
        yield 'a stop past the antimeridian' => [['stops' => [['lat' => 50.4925, 'lng' => -180.5], ['lat' => 50.4745, 'lng' => 5.8627]]] + self::body()];
        yield 'a stop item past 80 characters' => [['stops' => [['lat' => 50.4925, 'lng' => 5.8633, 'item' => str_repeat('i', 81)], ['lat' => 50.4745, 'lng' => 5.8627]]] + self::body()];
        yield 'a stop name past 160 characters' => [['stops' => [['lat' => 50.4925, 'lng' => 5.8633, 'name' => str_repeat('n', 161)], ['lat' => 50.4745, 'lng' => 5.8627]]] + self::body()];
        yield 'a stop item that is not a string' => [['stops' => [['lat' => 50.4925, 'lng' => 5.8633, 'item' => 12], ['lat' => 50.4745, 'lng' => 5.8627]]] + self::body()];
        yield 'a line that is a string' => [['line' => 'LINESTRING(5.8 50.4, 5.83 50.4)'] + self::body()];
        yield 'a line that is a Point' => [['line' => ['type' => 'Point', 'coordinates' => [5.8633, 50.4925]]] + self::body()];
        yield 'a line of one point' => [['line' => ['type' => 'LineString', 'coordinates' => [[5.8633, 50.4925]]]] + self::body()];
        yield 'a line of 5001 points' => [['line' => ['type' => 'LineString', 'coordinates' => array_fill(0, 5001, [5.8633, 50.4925])]] + self::body()];
        yield 'a line point off the planet' => [['line' => ['type' => 'LineString', 'coordinates' => [[5.8633, 50.4925], [185.0, 50.4745]]]] + self::body()];
        yield 'a line point that is not a pair' => [['line' => ['type' => 'LineString', 'coordinates' => [[5.8633, 50.4925], [5.8627]]]] + self::body()];
        yield 'a distance that is a string' => [['distanceM' => 'far'] + self::body()];
        yield 'a negative distance' => [['distanceM' => -1] + self::body()];
        yield 'a negative ascent' => [['ascentM' => -5] + self::body()];
        yield 'an ascent past 100000' => [['ascentM' => 100001] + self::body()];
        yield 'a descent past 100000' => [['descentM' => 100001] + self::body()];
        yield 'no descent' => [array_diff_key(self::body(), ['descentM' => 1])];
    }

    #[DataProvider('invalid')]
    public function testAnInvalidBodyIsRefusedAsAWhole(mixed $payload): void
    {
        self::assertNull(RideInput::parse($payload));
    }

    public function testTheBoundsAreInclusive(): void
    {
        $body = [
            'name' => str_repeat('n', 80),
            'stops' => array_fill(0, 25, ['lat' => 50.4925, 'lng' => 5.8633, 'item' => str_repeat('i', 80), 'name' => str_repeat('n', 160)]),
            'line' => ['type' => 'LineString', 'coordinates' => array_fill(0, 5000, [180.0, -90.0])],
            'ascentM' => 100000,
            'descentM' => 0,
        ] + self::body();

        $input = RideInput::parse($body);

        self::assertNotNull($input);
        self::assertCount(25, $input->stops);
        self::assertCount(5000, $input->line);
        self::assertSame(100000, $input->ascentM);
    }
}
