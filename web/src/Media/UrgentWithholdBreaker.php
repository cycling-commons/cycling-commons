<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media;

use App\Settings\SettingsRegistry;
use App\Settings\SystemSettings;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Site-wide budget for anonymous auto-withhold. Over budget: queue, do not hide.
 *
 * @see docs/specs/photo-uploads.md §6c
 *
 * @api
 */
final class UrgentWithholdBreaker
{
    /** One key for the whole site. */
    private const string KEY = 'global';

    private readonly CacheStorage $storage;

    public function __construct(
        #[Autowire(service: 'cache.media_urgent_breaker_limiter')]
        CacheItemPoolInterface $pool,
        private readonly SystemSettings $settings,
        private readonly LoggerInterface $logger,
        private readonly UrgentWithholdAlert $alert,
    ) {
        $this->storage = new CacheStorage($pool);
    }

    /**
     * Built per call: both limits are runtime-editable.
     *
     * @return array{hourly: LimiterInterface, daily: LimiterInterface}
     *
     * @see docs/specs/system-configuration.md §2
     */
    private function limiters(): array
    {
        return [
            'hourly' => $this->window(SettingsRegistry::MEDIA_URGENT_BREAKER_HOURLY, '1 hour'),
            'daily' => $this->window(SettingsRegistry::MEDIA_URGENT_BREAKER_DAILY, '1 day'),
        ];
    }

    private function window(string $setting, string $interval): LimiterInterface
    {
        return (new RateLimiterFactory([
            'id' => $setting,
            'policy' => 'sliding_window',
            'limit' => $this->settings->get($setting),
            'interval' => $interval,
        ], $this->storage))->create(self::KEY);
    }

    /**
     * Consume one unit. True = this report may withhold; false = breaker open.
     * Both windows are consumed even when the first refuses, so they stay in step.
     */
    public function allowWithhold(): bool
    {
        $limiters = $this->limiters();
        $hourly = $limiters['hourly']->consume()->isAccepted();
        $daily = $limiters['daily']->consume()->isAccepted();

        if ($hourly && $daily) {
            return true;
        }

        $this->logger->critical(
            'Auto-withhold circuit breaker is open: urgent photo reports are queuing instead of withholding.',
            ['hourly_budget_left' => $hourly, 'daily_budget_left' => $daily],
        );
        $this->alert->breakerOpened();

        return false;
    }

    /**
     * Peek without spending. consume(0)->isAccepted() is true at hits==limit; remaining tokens is not.
     */
    public function isOpen(): bool
    {
        $limiters = $this->limiters();

        return $limiters['hourly']->consume(0)->getRemainingTokens() < 1
            || $limiters['daily']->consume(0)->getRemainingTokens() < 1;
    }
}
