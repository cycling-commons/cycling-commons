<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Twig;

use App\Account\DateFormat;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Every human-readable date on the site, written the way the reader asked for
 * (docs/specs/account-and-auth.md §9).
 *
 * The point of routing all of them through one filter is that a preference is
 * only worth having if it is honoured everywhere: a settings dropdown that
 * fixes eight dates and misses the ninth is worse than no dropdown, because the
 * rider now believes the site listens.
 *
 * `|date('c')` stays exactly where it is. The machine-readable `datetime`
 * attribute of a `<time>` element is defined by HTML to be ISO 8601 and has
 * nothing to do with what a person wants to read — these filters format the
 * ELEMENT'S TEXT, never its attribute.
 *
 * Formatting goes through ICU rather than PHP's `date()` because month names
 * have to come out in the reader's language, and because ICU is what the two
 * locale-following options (Auto and Long) are defined in terms of.
 *
 * @api Auto-registered Twig extension.
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
            // For the base template, which hands the same preference to the
            // client so JS-rendered dates cannot disagree with server-rendered
            // ones on the same page.
            new TwigFunction('cc_user_date_format', fn (): string => $this->preference()->value),
        ];
    }

    /** A calendar date: "1 Aug 2026", "2026-08-01", "01-08-2026", … */
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

    /** A date with the time of day appended, in the notation that format implies. */
    public function dateTime(\DateTimeInterface|string|int|null $value): string
    {
        $when = self::coerce($value);
        if (null === $when) {
            return '';
        }

        $format = $this->preference();
        $pattern = $format->pattern();

        // With an explicit date pattern the time pattern joins it directly. With
        // a locale-following one there is no pattern to append to, so ICU's own
        // SHORT time style is used — which is already 24- or 12-hour according
        // to the locale, and is a better answer than anything hand-assembled.
        $formatter = null !== $pattern
            ? $this->formatter(\IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $pattern.' '.$format->timePattern())
            : $this->formatter($format->localeDateStyle(), \IntlDateFormatter::SHORT, null);

        return $formatter->format($when) ?: '';
    }

    /**
     * Month and year, for "member since". Always written out, never numeric:
     * "08/2026" is not something anybody says, and this string exists to be
     * read as prose.
     */
    public function month(\DateTimeInterface|string|int|null $value): string
    {
        $when = self::coerce($value);

        return null === $when
            ? ''
            : ($this->formatter(\IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'LLLL yyyy')->format($when) ?: '');
    }

    /**
     * The reader's choice, or Auto for anyone not signed in. Anonymous visitors
     * have no profile to hold a preference, so they get the locale's own form —
     * which is what Auto means anyway.
     */
    private function preference(): DateFormat
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getDateFormat() : DateFormat::Auto;
    }

    private function formatter(int $dateStyle, int $timeStyle, ?string $pattern): \IntlDateFormatter
    {
        $formatter = new \IntlDateFormatter($this->locale(), $dateStyle, $timeStyle);
        if (null !== $pattern) {
            $formatter->setPattern($pattern);
        }

        return $formatter;
    }

    /**
     * The language the page is being rendered in — not the rider's stored
     * locale. A signed-in Dutch rider who follows a German link is reading a
     * German page, and a Dutch month name in the middle of it would be a bug.
     */
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
            // An unparseable date is a data problem somewhere upstream; it is
            // not a reason to 500 the page that was only trying to print it.
            return null;
        }
    }
}
