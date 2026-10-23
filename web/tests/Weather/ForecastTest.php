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
 * null past the service's reach, with no service, and with no answer; an ask
 * of the cache only never a request.
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
            'time' => ['2026-10-18'], 'wind_speed_10m_max' => [28.1], 'wind_gusts_10m_max' => [52.4], 'wind_direction_10m_dominant' => [225], 'temperature_2m_max' => [14.2], 'precipitation_sum' => [0.4],
        ]]));
    }

    public function testThePointIsRoundedBeforeTheRequestAndTheDayIsReadBack(): void
    {
        $f = $this->forecast(self::day());

        $day = $f->forDay(50.4825, 5.8630, '2026-10-18');

        self::assertSame(['date' => '2026-10-18', 'lat' => 50.5, 'lng' => 5.9, 'windKmh' => 28.1, 'gustKmh' => 52.4, 'windFromDeg' => 225, 'tempMaxC' => 14.2, 'rainMm' => 0.4, 'hours' => null], $day);
        self::assertCount(1, $this->asked);
        self::assertStringContainsString('latitude=50.5&longitude=5.9', $this->asked[0]);
        self::assertStringContainsString('start_date=2026-10-18&end_date=2026-10-18', $this->asked[0]);
        self::assertStringContainsString('timezone=auto', $this->asked[0]);
    }

    public function testADayWithoutGustsKeepsItsOtherFiguresAndAGustOfNull(): void
    {
        $f = $this->forecast(new MockResponse((string) json_encode(['daily' => [
            'time' => ['2026-10-18'], 'wind_speed_10m_max' => [12.0], 'wind_direction_10m_dominant' => [10], 'temperature_2m_max' => [18.0], 'precipitation_sum' => [0.0],
        ]])));

        $day = $f->forDay(50.5, 5.9, '2026-10-18');

        self::assertNotNull($day);
        self::assertNull($day['gustKmh']);
        self::assertSame(12.0, $day['windKmh']);
        self::assertStringContainsString('wind_gusts_10m_max', $this->asked[0]);
    }

    public function testTheDaysHoursComeBackInLocalOrderAndAGapLeavesThemOut(): void
    {
        $hourly = ['time' => [], 'wind_speed_10m' => [], 'wind_gusts_10m' => [], 'wind_direction_10m' => [], 'temperature_2m' => [], 'precipitation' => [], 'relative_humidity_2m' => [], 'dew_point_2m' => [], 'soil_temperature_0cm' => [], 'snowfall' => [], 'weather_code' => []];
        for ($h = 0; $h < 24; ++$h) {
            $hourly['time'][] = sprintf('2026-10-18T%02d:00', $h);
            $hourly['wind_speed_10m'][] = 10.0 + $h;
            $hourly['wind_gusts_10m'][] = 20.0 + $h;
            $hourly['wind_direction_10m'][] = 180;
            $hourly['temperature_2m'][] = 8.5;
            $hourly['precipitation'][] = 13 === $h ? 1.2 : 0.0;
            $hourly['relative_humidity_2m'][] = 81;
            $hourly['dew_point_2m'][] = 5.4;
            $hourly['soil_temperature_0cm'][] = 7 === $h ? -0.6 : 6.1;
            $hourly['snowfall'][] = 0.0;
            $hourly['weather_code'][] = 13 === $h ? 61 : 3;
        }
        $daily = ['time' => ['2026-10-18'], 'wind_speed_10m_max' => [33.0], 'wind_gusts_10m_max' => [43.0], 'wind_direction_10m_dominant' => [180], 'temperature_2m_max' => [8.5], 'precipitation_sum' => [1.2]];

        $day = $this->forecast(new MockResponse((string) json_encode(['daily' => $daily, 'hourly' => $hourly])))->forDay(50.5, 5.9, '2026-10-18');

        self::assertNotNull($day);
        self::assertNotNull($day['hours']);
        self::assertCount(24, $day['hours']);
        self::assertSame(['windKmh' => 23.0, 'gustKmh' => 33.0, 'fromDeg' => 180, 'tempC' => 8.5, 'rainMm' => 1.2, 'humidity' => 81, 'dewC' => 5.4, 'groundC' => 6.1, 'snowCm' => 0.0, 'code' => 61], $day['hours'][13]);
        self::assertSame(-0.6, $day['hours'][7]['groundC']);
        self::assertStringContainsString('hourly=wind_speed_10m%2Cwind_gusts_10m%2Cwind_direction_10m%2Ctemperature_2m%2Cprecipitation%2Crelative_humidity_2m%2Cdew_point_2m%2Csoil_temperature_0cm%2Csnowfall%2Cweather_code', $this->asked[0]);
        // An hour without the extras keeps its figures, the extras null.
        unset($hourly['relative_humidity_2m'], $hourly['weather_code']);
        $bare = $this->forecast(new MockResponse((string) json_encode(['daily' => $daily, 'hourly' => $hourly])))->forDay(50.5, 5.9, '2026-10-18');
        self::assertNotNull($bare);
        self::assertNotNull($bare['hours']);
        self::assertNull($bare['hours'][13]['humidity']);
        self::assertNull($bare['hours'][13]['code']);

        $hourly['temperature_2m'][7] = null;
        $gap = $this->forecast(new MockResponse((string) json_encode(['daily' => $daily, 'hourly' => $hourly])))->forDay(50.5, 5.9, '2026-10-18');
        self::assertNotNull($gap, 'the day stands without its hours');
        self::assertNull($gap['hours']);
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

    public function testAnAskOfTheCacheOnlyNeverReachesTheService(): void
    {
        $f = $this->forecast(self::day());

        self::assertNull($f->forDay(50.5, 5.9, '2026-10-18', cachedOnly: true), 'the cache does not hold it yet');
        self::assertCount(0, $this->asked);
        self::assertTrue($f->answers('2026-10-18'), 'an ask without the cache would get it');
        $day = $f->forDay(50.5, 5.9, '2026-10-18');
        self::assertSame($day, $f->forDay(50.4825, 5.8630, '2026-10-18', cachedOnly: true), 'the same rounded point and date');
        self::assertCount(1, $this->asked);

        self::assertFalse($f->answers('2026-11-02'), 'past the reach');
        self::assertFalse($this->forecast(self::day(), '')->answers('2026-10-18'), 'no service');
    }

    public function testRoundingIsToATenthOfADegree(): void
    {
        self::assertSame(50.5, Forecast::round(50.4825));
        self::assertSame(5.9, Forecast::round(5.8630));
        self::assertSame(-0.1, Forecast::round(-0.06));
    }
}
