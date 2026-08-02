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
 * The site-wide budget for auto-withholding photos on an anonymous report
 * (docs/specs/photo-uploads.md §6c).
 *
 * Why this exists. The urgent category — intimate imagery, or a child is
 * depicted — takes a photo off the map before any curator looks, because a day
 * online is the one outcome with a real cost. That makes it the single lever an
 * anonymous stranger holds that changes anything, and the per-IP limiter in
 * front of it is **no defence against a distributed attacker**: per-IP limits
 * bound one IP, and a proxy pool is many. Every photo's uuid is in its public
 * image URL, so the target list is free. Without a global budget, a botnet
 * could withhold one photo per IP per day across the whole corpus.
 *
 * So the budget is keyed on ONE key for the entire site. Under it, nothing
 * changes. Over it, urgent reports still file, still pin to the top of the
 * desk, and still alert — they simply stop taking photos down by themselves,
 * and a human decides instead.
 *
 * The degrade is safe precisely because of what trips it: genuine reports of
 * this kind are rare, so a burst large enough to exhaust the budget is itself
 * the evidence that its members are not genuine. The isolated real report —
 * the case the lever exists for — never comes near the cap.
 *
 * Both windows must accept: the hourly budget stops a fast flood, the daily one
 * stops a slow drip that would never trip the hourly.
 *
 * @api Consulted by MediaTakedownService; peeked at by the moderation desk.
 */
final class UrgentWithholdBreaker
{
    /**
     * One key for the whole site. Not the photo, not the reporter — the budget
     * is global or it is not a defence.
     */
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
     * The two budgets, built per call rather than wired as configured
     * limiters, because both limits are runtime-editable
     * (system-configuration.md §2). An attack is exactly the moment nobody can
     * wait for a deploy to change a number, so the number lives in the
     * database and the window is constructed around it.
     *
     * @return array{hourly: LimiterInterface, daily: LimiterInterface}
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
     * Consumes one unit of the budget. True when this report may take the photo
     * down on its own; false when the breaker is open and a curator decides.
     *
     * Both windows are consumed even when the first refuses, so the two stay in
     * step: a request that was refused an auto-withhold has still happened, and
     * the daily window should know about it.
     */
    public function allowWithhold(): bool
    {
        $limiters = $this->limiters();
        $hourly = $limiters['hourly']->consume()->isAccepted();
        $daily = $limiters['daily']->consume()->isAccepted();

        if ($hourly && $daily) {
            return true;
        }

        // CRITICAL, not warning: this is either an attack on the takedown
        // route or something genuinely terrible happening at scale, and both
        // want a human now.
        $this->logger->critical(
            'Auto-withhold circuit breaker is open: urgent photo reports are queuing instead of withholding.',
            ['hourly_budget_left' => $hourly, 'daily_budget_left' => $daily],
        );
        // Throttled inside the alerter — the flood that opens the breaker keeps
        // arriving, and a mail per report would be a second denial of service
        // pointed at the person who has to read them.
        $this->alert->breakerOpened();

        return false;
    }

    /**
     * Is the breaker open right now? Peeks without spending, so the desk can
     * report the state without moving it.
     *
     * Reads the REMAINING TOKENS rather than `consume(0)->isAccepted()`: asking
     * a sliding window whether it accepts zero tokens is true even when the
     * budget is exactly spent (`hits + 0 > limit` is false at `hits == limit`),
     * so an accepted-peek would call a fully open breaker closed.
     */
    public function isOpen(): bool
    {
        $limiters = $this->limiters();

        return $limiters['hourly']->consume(0)->getRemainingTokens() < 1
            || $limiters['daily']->consume(0)->getRemainingTokens() < 1;
    }
}
