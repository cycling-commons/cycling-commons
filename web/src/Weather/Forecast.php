<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Weather;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The forecast behind a planned ride's date (docs/specs/route-planner.md
 * section 4.4 and 8): Open-Meteo's daily forecast for one point and one
 * day, the day's dominant wind direction, highest wind speed and highest
 * gust at 10 m, the highest temperature and the rain sum, and the same
 * figures for each of the day's 24 local hours (`hours`, null when the
 * service gives no hours), so the panel reads the hours the rider rides;
 * with the rounded point it is for, so the panel's link to Open-Meteo names the same point;
 * a gust the service does not give is null. The point is rounded to a tenth
 * of a degree before anything leaves our server, so the service sees our
 * address, a 10 km cell and a date, nothing about the rider; the answer is
 * cached under that cell and date, so a ride dragged around a town costs one
 * request an afternoon. Null when the service is off, does not answer, or
 * the date is past its reach: the panel then shows no line. Asked from the
 * cache only, it answers what the cache holds and never asks the service:
 * null then also means the cache does not hold it ({@see answers()} tells the
 * two apart).
 *
 * Every ask that gets this far is counted in ForecastUsage: answered from the
 * cache, or sent to Open-Meteo with what it counts as there and whether it
 * failed or was refused (route-planner.md section 8).
 *
 * @api
 */
final class Forecast
{
    /** How far ahead the service forecasts. */
    public const int DAYS = 16;
    public const int CACHE_S = 10800;
    private const float TIMEOUT_S = 5.0;

    /** The day's figures asked of Open-Meteo. */
    private const array DAILY = ['wind_speed_10m_max', 'wind_gusts_10m_max', 'wind_direction_10m_dominant', 'temperature_2m_max', 'precipitation_sum'];

    /** The hour's figures asked of Open-Meteo, for the day's 24 hours. */
    private const array HOURLY = ['wind_speed_10m', 'wind_gusts_10m', 'wind_direction_10m', 'temperature_2m', 'precipitation', 'relative_humidity_2m', 'dew_point_2m', 'soil_temperature_0cm', 'snowfall', 'weather_code'];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly string $forecastUrl,
        private readonly ?ForecastUsage $usage = null,
    ) {
    }

    /**
     * What one request counts as against Open-Meteo's limits: a request for
     * more than 10 figures, or for more than 14 days, counts as more than one
     * call, in proportion (open-meteo.com/en/pricing). Ours asks 15 figures for
     * one day: 1.5.
     */
    public static function callWeight(): float
    {
        return max(1.0, (\count(self::DAILY) + \count(self::HOURLY)) / 10) * max(1.0, 1 / 14);
    }

    /** A coordinate to a tenth of a degree: about 10 km, the cell a forecast is for. */
    public static function round(float $v): float
    {
        return round($v, 1);
    }

    /** Whether the service is on and the date within its reach: whether an ask for that date can get a forecast. */
    public function answers(string $date): bool
    {
        return '' !== $this->forecastUrl && $this->withinReach($date);
    }

    /**
     * @return array{date: string, lat: float, lng: float, windKmh: float, gustKmh: float|null, windFromDeg: int, tempMaxC: float, rainMm: float, hours: list<array{windKmh: float, gustKmh: float|null, fromDeg: int, tempC: float, rainMm: float, humidity: int|null, dewC: float|null, groundC: float|null, snowCm: float|null, code: int|null}>|null}|null
     */
    public function forDay(float $lat, float $lng, string $date, bool $cachedOnly = false): ?array
    {
        if (!$this->answers($date)) {
            return null;
        }
        $lat = self::round($lat);
        $lng = self::round($lng);
        $key = sprintf('forecast4.%s.%s.%s', str_replace(['.', '-'], ['_', 'm'], (string) $lat), str_replace(['.', '-'], ['_', 'm'], (string) $lng), $date);
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            $this->usage?->asked(true);
            /** @var array{date: string, lat: float, lng: float, windKmh: float, gustKmh: float|null, windFromDeg: int, tempMaxC: float, rainMm: float, hours: list<array{windKmh: float, gustKmh: float|null, fromDeg: int, tempC: float, rainMm: float, humidity: int|null, dewC: float|null, groundC: float|null, snowCm: float|null, code: int|null}>|null} $hit */
            $hit = $item->get();

            return $hit;
        }
        if ($cachedOnly) {
            return null;
        }
        $refused = false;
        $day = $this->fetch($lat, $lng, $date, $refused);
        $this->usage?->asked(false, self::callWeight(), null === $day, $refused);
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
     * `$refused` comes back true when Open-Meteo answered that we are over its limit (HTTP 429).
     *
     * @return array{date: string, lat: float, lng: float, windKmh: float, gustKmh: float|null, windFromDeg: int, tempMaxC: float, rainMm: float, hours: list<array{windKmh: float, gustKmh: float|null, fromDeg: int, tempC: float, rainMm: float, humidity: int|null, dewC: float|null, groundC: float|null, snowCm: float|null, code: int|null}>|null}|null
     */
    private function fetch(float $lat, float $lng, string $date, bool &$refused): ?array
    {
        $url = rtrim($this->forecastUrl, '/').'/v1/forecast?'.http_build_query([
            'latitude' => sprintf('%.1f', $lat),
            'longitude' => sprintf('%.1f', $lng),
            'daily' => implode(',', self::DAILY),
            'hourly' => implode(',', self::HOURLY),
            'timezone' => 'auto',
            'start_date' => $date,
            'end_date' => $date,
        ], '', '&', \PHP_QUERY_RFC3986);
        try {
            $data = $this->http->request('GET', $url, ['timeout' => self::TIMEOUT_S, 'max_duration' => self::TIMEOUT_S])->toArray();
        } catch (\Throwable $e) {
            $refused = $e instanceof HttpExceptionInterface && 429 === $e->getResponse()->getStatusCode();
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
        $gust = $daily['wind_gusts_10m_max'][0] ?? null;
        if (!is_numeric($wind) || !is_numeric($from) || !is_numeric($temp) || !is_numeric($rain)) {
            return null;
        }

        return ['date' => $date, 'lat' => $lat, 'lng' => $lng, 'windKmh' => (float) $wind, 'gustKmh' => is_numeric($gust) ? (float) $gust : null, 'windFromDeg' => (int) round((float) $from), 'tempMaxC' => (float) $temp, 'rainMm' => (float) $rain, 'hours' => self::hours($data['hourly'] ?? null, $date)];
    }

    /**
     * The day's 24 local hours, 00:00 first; null unless every hour of the date is there with its wind, direction,
     * temperature and rain (a gust may be null, as for the day). The humidity, the dew point, the ground's
     * temperature, the snowfall and the weather code read wind chill, heat and the chance of ice (route-planner.md
     * section 4.4); each is null where the service gives none.
     *
     * @return list<array{windKmh: float, gustKmh: float|null, fromDeg: int, tempC: float, rainMm: float, humidity: int|null, dewC: float|null, groundC: float|null, snowCm: float|null, code: int|null}>|null
     */
    private static function hours(mixed $hourly, string $date): ?array
    {
        if (!\is_array($hourly) || !\is_array($hourly['time'] ?? null)) {
            return null;
        }
        $out = [];
        for ($h = 0; $h < 24; ++$h) {
            $at = $hourly['time'][$h] ?? null;
            $wind = $hourly['wind_speed_10m'][$h] ?? null;
            $from = $hourly['wind_direction_10m'][$h] ?? null;
            $temp = $hourly['temperature_2m'][$h] ?? null;
            $rain = $hourly['precipitation'][$h] ?? null;
            $gust = $hourly['wind_gusts_10m'][$h] ?? null;
            if (sprintf('%sT%02d:00', $date, $h) !== $at || !is_numeric($wind) || !is_numeric($from) || !is_numeric($temp) || !is_numeric($rain)) {
                return null;
            }
            $num = static fn (string $k): ?float => is_numeric($hourly[$k][$h] ?? null) ? (float) $hourly[$k][$h] : null;
            $humidity = $num('relative_humidity_2m');
            $code = $num('weather_code');
            $out[] = [
                'windKmh' => (float) $wind, 'gustKmh' => is_numeric($gust) ? (float) $gust : null, 'fromDeg' => (int) round((float) $from), 'tempC' => (float) $temp, 'rainMm' => (float) $rain,
                'humidity' => null === $humidity ? null : (int) round($humidity), 'dewC' => $num('dew_point_2m'), 'groundC' => $num('soil_temperature_0cm'), 'snowCm' => $num('snowfall'), 'code' => null === $code ? null : (int) $code,
            ];
        }

        return $out;
    }
}
