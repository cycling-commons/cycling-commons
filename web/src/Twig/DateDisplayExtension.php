<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Twig;

use App\Account\DateFormat;
use App\Account\TimeFormat;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Dates in the reader's format (docs/specs/account-and-auth.md §9). `|date('c')` stays ISO for `<time>`.
 *
 * @api
 */
final class DateDisplayExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('cc_date', $this->date(...)),
            new TwigFilter('cc_datetime', $this->dateTime(...)),
            new TwigFilter('cc_month', $this->month(...)),
        ];
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cc_user_date_format', fn (): string => $this->preference()->value),
            new TwigFunction('cc_user_time_format', fn (): string => $this->timePreference()->value),
        ];
    }

    public function date(\DateTimeInterface|string|int|null $value): string
    {
        $when = self::coerce($value);
        if (null === $when) {
            return '';
        }

        $format = $this->preference();
        $pattern = $format->pattern();

        return $this->formatter($format->localeDateStyle(), \IntlDateFormatter::NONE, $pattern)
            ->format($when) ?: '';
    }

    public function dateTime(\DateTimeInterface|string|int|null $value): string
    {
        $when = self::coerce($value);
        if (null === $when) {
            return '';
        }

        $date = $this->preference();
        $time = $this->timePreference();
        $datePattern = $date->pattern();
        $timePattern = $time->pattern();

        if (null === $datePattern && null === $timePattern) {
            return $this->formatter($date->localeDateStyle(), \IntlDateFormatter::SHORT, null)->format($when) ?: '';
        }

        $datePart = null !== $datePattern
            ? $this->formatter(\IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $datePattern)->format($when)
            : $this->formatter($date->localeDateStyle(), \IntlDateFormatter::NONE, null)->format($when);

        $timePart = null !== $timePattern
            ? $this->formatter(\IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $timePattern)->format($when)
            : $this->formatter(\IntlDateFormatter::NONE, \IntlDateFormatter::SHORT, null)->format($when);

        return trim(($datePart ?: '').' '.($timePart ?: ''));
    }

    /** Month and year for "member since". */
    public function month(\DateTimeInterface|string|int|null $value): string
    {
        $when = self::coerce($value);

        return null === $when
            ? ''
            : ($this->formatter(\IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'LLLL yyyy')->format($when) ?: '');
    }

    private function preference(): DateFormat
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getDateFormat() : DateFormat::Auto;
    }

    private function timePreference(): TimeFormat
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getTimeFormat() : TimeFormat::Auto;
    }

    private function formatter(int $dateStyle, int $timeStyle, ?string $pattern): \IntlDateFormatter
    {
        $formatter = new \IntlDateFormatter($this->locale(), $dateStyle, $timeStyle);
        if (null !== $pattern) {
            $formatter->setPattern($pattern);
        }

        return $formatter;
    }

    /** Page language, not the rider's stored locale. */
    private function locale(): string
    {
        return $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';
    }

    private static function coerce(\DateTimeInterface|string|int|null $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return \is_int($value)
                ? (new \DateTimeImmutable())->setTimestamp($value)
                : new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
