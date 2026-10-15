<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Weather;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The forecast behind a planned ride's date (docs/specs/route-planner.md
 * section 4.4 and 8): Open-Meteo's daily forecast for one point and one
 * day, the day's dominant wind direction and highest wind speed at 10 m,
 * the highest temperature and the rain sum. The point is rounded to a tenth
 * of a degree before anything leaves our server, so the service sees our
 * address, a 10 km cell and a date, nothing about the rider; the answer is
 * cached under that cell and date, so a ride dragged around a town costs one
 * request an afternoon. Null when the service is off, does not answer, or
 * the date is past its reach: the panel then shows no line.
 *
 * @api
 */
final class Forecast
{
    /** How far ahead the service forecasts. */
    public const int DAYS = 16;
    public const int CACHE_S = 10800;
    private const float TIMEOUT_S = 5.0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly string $forecastUrl,
    ) {
    }

    /** A coordinate to a tenth of a degree: about 10 km, the cell a forecast is for. */
    public static function round(float $v): float
    {
        return round($v, 1);
    }

    /**
     * @return array{date: string, windKmh: float, windFromDeg: int, tempMaxC: float, rainMm: float}|null
     */
    public function forDay(float $lat, float $lng, string $date): ?array
    {
        if ('' === $this->forecastUrl || !$this->withinReach($date)) {
            return null;
        }
        $lat = self::round($lat);
        $lng = self::round($lng);
        $key = sprintf('forecast.%s.%s.%s', str_replace(['.', '-'], ['_', 'm'], (string) $lat), str_replace(['.', '-'], ['_', 'm'], (string) $lng), $date);
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            /** @var array{date: string, windKmh: float, windFromDeg: int, tempMaxC: float, rainMm: float} $hit */
            $hit = $item->get();

            return $hit;
        }
        $day = $this->fetch($lat, $lng, $date);
        if (null !== $day) {
            $item->set($day);
            $item->expiresAfter(self::CACHE_S);
            $this->cache->save($item);
        }

        return $day;
    }

    /** Yesterday to DAYS ahead, by our clock's date; anything else is not asked. */
    private function withinReach(string $date): bool
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (false === $day || $day->format('Y-m-d') !== $date) {
            return false;
        }
        $today = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $offset = (int) $today->diff($day)->format('%r%a');

        return $offset >= -1 && $offset <= self::DAYS;
    }

    /**
     * @return array{date: string, windKmh: float, windFromDeg: int, tempMaxC: float, rainMm: float}|null
     */
    private function fetch(float $lat, float $lng, string $date): ?array
    {
        $url = rtrim($this->forecastUrl, '/').'/v1/forecast?'.http_build_query([
            'latitude' => sprintf('%.1f', $lat),
            'longitude' => sprintf('%.1f', $lng),
            'daily' => 'wind_speed_10m_max,wind_direction_10m_dominant,temperature_2m_max,precipitation_sum',
            'timezone' => 'auto',
            'start_date' => $date,
            'end_date' => $date,
        ], '', '&', \PHP_QUERY_RFC3986);
        try {
            $data = $this->http->request('GET', $url, ['timeout' => self::TIMEOUT_S, 'max_duration' => self::TIMEOUT_S])->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('forecast lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
        $daily = $data['daily'] ?? null;
        if (!\is_array($daily) || ($daily['time'][0] ?? null) !== $date) {
            return null;
        }
        $wind = $daily['wind_speed_10m_max'][0] ?? null;
        $from = $daily['wind_direction_10m_dominant'][0] ?? null;
        $temp = $daily['temperature_2m_max'][0] ?? null;
        $rain = $daily['precipitation_sum'][0] ?? null;
        if (!is_numeric($wind) || !is_numeric($from) || !is_numeric($temp) || !is_numeric($rain)) {
            return null;
        }

        return ['date' => $date, 'windKmh' => (float) $wind, 'windFromDeg' => (int) round((float) $from), 'tempMaxC' => (float) $temp, 'rainMm' => (float) $rain];
    }
}
