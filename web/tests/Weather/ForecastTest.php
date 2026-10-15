<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Weather;

use App\Weather\Forecast;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The forecast behind a planned ride's date (docs/specs/route-planner.md
 * section 4.4 and 8): the point rounded to a tenth of a degree before the
 * request, one request for two asks of the same rounded point and date,
 * null past the service's reach, with no service, and with no answer.
 */
final class ForecastTest extends TestCase
{
    /** @var list<string> */
    private array $asked = [];

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-10-15 09:00:00', new \DateTimeZone('UTC'));
            }
        };
    }

    private function forecast(?MockResponse $response, string $url = 'https://forecast.test'): Forecast
    {
        $http = new MockHttpClient(function (string $method, string $u) use ($response): MockResponse {
            $this->asked[] = $u;

            return $response ?? new MockResponse('', ['error' => 'Could not resolve host']);
        });

        return new Forecast($http, new ArrayAdapter(), new NullLogger(), $this->clock(), $url);
    }

    private static function day(): MockResponse
    {
        return new MockResponse((string) json_encode(['daily' => [
            'time' => ['2026-10-18'], 'wind_speed_10m_max' => [28.1], 'wind_direction_10m_dominant' => [225], 'temperature_2m_max' => [14.2], 'precipitation_sum' => [0.4],
        ]]));
    }

    public function testThePointIsRoundedBeforeTheRequestAndTheDayIsReadBack(): void
    {
        $f = $this->forecast(self::day());

        $day = $f->forDay(50.4825, 5.8630, '2026-10-18');

        self::assertSame(['date' => '2026-10-18', 'windKmh' => 28.1, 'windFromDeg' => 225, 'tempMaxC' => 14.2, 'rainMm' => 0.4], $day);
        self::assertCount(1, $this->asked);
        self::assertStringContainsString('latitude=50.5&longitude=5.9', $this->asked[0]);
        self::assertStringContainsString('start_date=2026-10-18&end_date=2026-10-18', $this->asked[0]);
        self::assertStringContainsString('timezone=auto', $this->asked[0]);
    }

    public function testTwoAsksOfTheSameRoundedPointAndDateAreOneRequest(): void
    {
        $f = $this->forecast(self::day());

        $f->forDay(50.4825, 5.8630, '2026-10-18');
        $f->forDay(50.5300, 5.9400, '2026-10-18');

        self::assertCount(1, $this->asked, 'a ride dragged around a town costs one request');
    }

    public function testADatePastTheServicesReachOrBeforeYesterdayIsNullWithoutARequest(): void
    {
        $f = $this->forecast(self::day());

        self::assertNull($f->forDay(50.5, 5.9, '2026-11-02'), '18 days on is past 16');
        self::assertNull($f->forDay(50.5, 5.9, '2026-10-01'));
        self::assertNull($f->forDay(50.5, 5.9, 'tomorrow'));
        self::assertCount(0, $this->asked);
        $this->forecast(self::day())->forDay(50.5, 5.9, '2026-10-31');
        self::assertCount(1, $this->asked, '16 days on is the last day asked for');
    }

    public function testNoServiceAndNoAnswerAreBothNull(): void
    {
        self::assertNull($this->forecast(self::day(), '')->forDay(50.5, 5.9, '2026-10-18'));
        self::assertCount(0, $this->asked);
        self::assertNull($this->forecast(null)->forDay(50.5, 5.9, '2026-10-18'));
        self::assertNull($this->forecast(new MockResponse('{"daily":{"time":[]}}'))->forDay(50.5, 5.9, '2026-10-18'));
    }

    public function testRoundingIsToATenthOfADegree(): void
    {
        self::assertSame(50.5, Forecast::round(50.4825));
        self::assertSame(5.9, Forecast::round(5.8630));
        self::assertSame(-0.1, Forecast::round(-0.06));
    }
}
