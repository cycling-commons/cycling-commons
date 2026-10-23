<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Contribution;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * A contribution refused by a rate limit, with what the rider needs to know:
 * how many the period holds and from when the next one is taken
 * (LimitMessage words it). A TooManyRequestsHttpException, so every caller
 * that catches the plain one still does; its message stays the generic key
 * for a caller that shows only that.
 *
 * @api
 */
final class LimitReached extends TooManyRequestsHttpException
{
    /**
     * @param 'minute'|'hour'|'day' $per
     */
    public function __construct(
        public readonly int $limit,
        public readonly string $per,
        public readonly \DateTimeImmutable $retryAt,
    ) {
        parent::__construct(max(1, $retryAt->getTimestamp() - time()), 'contribute.error.rate_limited');
    }

    /**
     * @param 'minute'|'hour'|'day' $per the limiter's interval, as its config names it
     */
    public static function of(RateLimit $refused, string $per): self
    {
        return new self($refused->getLimit(), $per, $refused->getRetryAfter());
    }

    /** The message that names the number and the time: `contribute.error.rate_limited_hour`. */
    public function key(): string
    {
        return 'contribute.error.rate_limited_'.$this->per;
    }
}
