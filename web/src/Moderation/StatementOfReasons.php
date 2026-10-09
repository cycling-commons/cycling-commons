<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Moderation;

use App\Routing\LocalePrefix;
use App\Routing\LocalizedPath;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A DSA Article 17 statement of reasons, and its one wording.
 *
 * Every decision that restricts what a rider added, or their account, builds
 * one of these: a rejection, a removal, a hidden photo, a retired place, an
 * upheld report, a suspension, a removal of the account. The decision rides
 * the rider's message ({@see self::PARAM} in its body params) and goes out by
 * email in their language; an account decision goes by email alone. Both read
 * {@see lines()}, so the inbox and the mail cannot say different things.
 *
 * Article 17(3) lists what it must say, and each is a line here:
 *
 * - (a) what was decided and its scope: `decision`, `scope` (with the end date
 *   of a suspension);
 * - (b) the facts, and whether a report started it: `facts` (the curator's or
 *   administrator's own words), `facts_system` (what the system can state on
 *   its own), `source`;
 * - (c) whether automated means were used: `automated`, and the `decision`
 *   and `source` lines agree with it (`dsa_statement.decision_automated.*`,
 *   `dsa_statement.source.automated`);
 * - (d) and (e) the ground, law or our terms: `ground`, `basis`;
 * - (f) how to contest it: `redress` (reply to us; the terms explain the rest).
 *
 * @see docs/specs/content-reports.md §7
 *
 * @api
 */
final readonly class StatementOfReasons
{
    /** The body-param key a message carries its statement under. Not a placeholder: it never substitutes into a line. */
    public const string PARAM = '_statement';

    /** The longest facts text kept, the cap every message body has. */
    public const int FACTS_MAX = 2000;

    /**
     * @param string      $facts      the decider's own words; may be empty only when $factsKey states the facts
     * @param string      $reference  what to quote when replying: SUB-42, a report's uuid, ACCOUNT
     * @param string|null $subject    the name of what it is about, as the rider knows it
     * @param bool        $fromReport the decision followed a report (Article 16 notice)
     * @param bool        $automated  software decided or detected it without a person
     * @param string|null $factsKey   a catalogue line with the facts the system itself can state
     * @param string|null $answerPath a page where the decision can be answered (a copyright claim)
     * @param bool        $aboutPhoto it is about a photo; $subject then names the place it shows
     */
    public function __construct(
        public StatementDecision $decision,
        public StatementGround $ground,
        public string $facts,
        public string $reference,
        public ?string $subject = null,
        public bool $fromReport = false,
        public bool $automated = false,
        public ?\DateTimeImmutable $until = null,
        public ?string $factsKey = null,
        public ?string $answerPath = null,
        public bool $aboutPhoto = false,
    ) {
        if ('' === trim($facts) && null === $factsKey) {
            throw new \InvalidArgumentException('A statement of reasons states its facts.');
        }
        if (mb_strlen($facts) > self::FACTS_MAX) {
            throw new \InvalidArgumentException('moderate.error.note_too_long');
        }
        // An account is suspended or removed by an administrator, never by
        // software, and its lines say so.
        if ($automated && $decision->isAboutAccount()) {
            throw new \InvalidArgumentException('An account decision is an administrator\'s.');
        }
    }

    /** The same statement about a differently named subject. */
    public function withSubject(?string $subject): self
    {
        return new self(
            $this->decision, $this->ground, $this->facts, $this->reference, $subject,
            $this->fromReport, $this->automated, $this->until, $this->factsKey, $this->answerPath, $this->aboutPhoto,
        );
    }

    /** The same statement with another line of system facts. */
    public function withFactsKey(?string $factsKey): self
    {
        return new self(
            $this->decision, $this->ground, $this->facts, $this->reference, $this->subject,
            $this->fromReport, $this->automated, $this->until, $factsKey, $this->answerPath, $this->aboutPhoto,
        );
    }

    /**
     * The statement as it is stored on the message row.
     *
     * @return array<string, string|bool|null>
     */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision->value,
            'ground' => $this->ground->value,
            'facts' => $this->facts,
            'reference' => $this->reference,
            'subject' => $this->subject,
            'report' => $this->fromReport,
            'automated' => $this->automated,
            'until' => $this->until?->format(\DateTimeInterface::ATOM),
            'facts_key' => $this->factsKey,
            'answer' => $this->answerPath,
            'photo' => $this->aboutPhoto,
        ];
    }

    /** Read a stored statement back; null for anything that is not one. */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data)) {
            return null;
        }
        $decision = StatementDecision::tryFrom(self::str($data['decision'] ?? null) ?? '');
        $ground = StatementGround::tryFrom(self::str($data['ground'] ?? null) ?? '');
        $facts = self::str($data['facts'] ?? null) ?? '';
        $factsKey = self::str($data['facts_key'] ?? null);
        if (null === $decision || null === $ground || ('' === trim($facts) && null === $factsKey)) {
            return null;
        }
        $until = self::str($data['until'] ?? null);

        try {
            return new self(
                $decision,
                $ground,
                $facts,
                self::str($data['reference'] ?? null) ?? '',
                self::str($data['subject'] ?? null),
                true === ($data['report'] ?? false),
                true === ($data['automated'] ?? false),
                null !== $until ? new \DateTimeImmutable($until) : null,
                $factsKey,
                self::str($data['answer'] ?? null),
                true === ($data['photo'] ?? false),
            );
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * The statement in one language, every line already translated and plain
     * text (the template escapes it).
     *
     * @param bool   $replyByEmail the reader can answer by replying to the email this came in
     * @param string $timeZone     the reader's time zone, for the end of a suspension
     *
     * @return array{headline: string, decision: string, scope: string, facts: ?string, facts_system: ?string, source: string, automated: string, ground: string, basis: string, redress: string, answer: ?string, answer_path: ?string, reference: string}
     */
    public function lines(TranslatorInterface $translator, string $locale, bool $replyByEmail, string $timeZone = 'Europe/Amsterdam'): array
    {
        $t = static fn (string $key, array $params = []): string => $translator->trans($key, $params, null, $locale);
        $subject = trim((string) $this->subject);
        $params = [
            '%subject%' => match (true) {
                $this->aboutPhoto => '' !== $subject ? $t('dsa_statement.subject_photo_named', ['%name%' => $subject]) : $t('dsa_statement.subject_photo'),
                default => '' !== $subject ? $t('dsa_statement.subject_named', ['%name%' => $subject]) : $t('dsa_statement.subject_unnamed'),
            },
            '%until%' => null !== $this->until ? self::moment($this->until, $locale, $timeZone) : '',
        ];
        $facts = trim($this->facts);

        return [
            'headline' => $t($this->decision->headlineKey()),
            // Our checks acting alone have their own lines: none says a curator decided or looked.
            'decision' => $t(($this->automated ? 'dsa_statement.decision_automated.' : 'dsa_statement.decision.').$this->decision->value, $params),
            'scope' => $t('dsa_statement.scope.'.$this->decision->value, $params),
            'facts' => '' !== $facts ? $facts : null,
            'facts_system' => null !== $this->factsKey ? $t($this->factsKey) : null,
            'source' => $t($this->decision->isAboutAccount()
                ? ($this->fromReport ? 'dsa_statement.source.account_report' : 'dsa_statement.source.account_own')
                : ($this->fromReport ? 'dsa_statement.source.report' : ($this->automated ? 'dsa_statement.source.automated' : 'dsa_statement.source.own'))),
            'automated' => $t($this->automated ? 'dsa_statement.automated.yes' : ($this->decision->isAboutAccount() ? 'dsa_statement.automated.admin' : 'dsa_statement.automated.no')),
            'ground' => $t($this->ground->label()),
            'basis' => $t($this->ground->isLegal() ? 'dsa_statement.basis.law' : 'dsa_statement.basis.terms'),
            'redress' => $t(match (true) {
                $this->decision->isAboutAccount() => $replyByEmail ? 'dsa_statement.redress.account_reply' : 'dsa_statement.redress.account_contact',
                default => $replyByEmail ? 'dsa_statement.redress.reply' : 'dsa_statement.redress.contact',
            }),
            'answer' => null !== $this->answerPath ? $t('dsa_statement.copyright_answer') : null,
            'answer_path' => $this->answerPath,
            'reference' => $this->reference,
        ];
    }

    /** The absolute link to the terms in this language. */
    public static function termsUrl(string $siteUrl, string $locale): string
    {
        return rtrim($siteUrl, '/').(LocalePrefix::PATHS[$locale] ?? '').(LocalizedPath::TERMS[$locale] ?? LocalizedPath::TERMS['en']);
    }

    /** The absolute link to the contact page in this language. */
    public static function contactUrl(string $siteUrl, string $locale): string
    {
        return rtrim($siteUrl, '/').(LocalePrefix::PATHS[$locale] ?? '').(LocalizedPath::CONTACT[$locale] ?? LocalizedPath::CONTACT['en']);
    }

    /** A moment in the reader's language and zone, on the 24-hour clock. */
    public static function moment(\DateTimeImmutable $at, string $locale, string $timeZone): string
    {
        try {
            $zone = new \DateTimeZone($timeZone);
        } catch (\Exception) {
            $zone = new \DateTimeZone('UTC');
        }
        $local = $at->setTimezone($zone);
        // English as written in Europe, where the Commons is: 1 November 2026.
        $locale = 'en' === $locale ? 'en_GB' : $locale;
        // The date as the language writes it (1. November, 1 de noviembre),
        // then the time on the 24-hour clock and the zone's name.
        try {
            $date = (new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE, $zone))->format($local);
        } catch (\Throwable) {
            $date = false;
        }
        $time = \IntlDateFormatter::formatObject($local, 'HH:mm (zzzz)', $locale);

        return \is_string($date) && '' !== $date && $time
            ? $date.', '.$time
            : $local->format('Y-m-d H:i T');
    }

    private static function str(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
