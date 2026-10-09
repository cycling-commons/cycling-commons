<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Legal;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Emails every account about a significant change to a legal page, at least
 * LegalVersions::NOTICE_DAYS days before it applies, in each person's own
 * language, with what changes, the date, a link to the new text and how to
 * close the account. An essential service message: it goes whatever the news
 * setting. An account with an unconfirmed address that never signed in is not
 * mailed: the address may be a stranger's, typed by a bot, and the unverified
 * sweep deletes the account within a week. One that signed in before sign-in
 * needed a confirmed address is a person and is mailed
 * (docs/specs/account-and-auth.md §6.7). One row per account and version (`legal_notice_sent`) makes a run
 * that stopped halfway continue without mailing anyone twice. An address the
 * mail server refuses is logged by account id, skipped, and tried again by the
 * next run.
 *
 * @see docs/specs/privacy-notice.md, docs/specs/translations.md §6.2
 *
 * @api
 */
final readonly class LegalNotice
{
    private const array LOCALES = ['en', 'fr', 'nl', 'de', 'es'];

    public function __construct(
        private Connection $db,
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private LegalPageView $pages,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param 'privacy'|'terms'                                                                             $page
     * @param array{number: int, date: string, effective: string, significant: bool, changes: list<string>} $version
     *
     * @return array{sent: int, failed: int} accounts mailed in this run, and accounts the mail server refused
     *
     * @throws \DomainException when the version is not significant, applies too soon, or its new text is missing
     */
    public function announce(string $page, array $version, \DateTimeImmutable $today): array
    {
        if (!$version['significant']) {
            throw new \DomainException(\sprintf('%s version %d is not significant: it needs no announcement.', $page, $version['number']));
        }
        $earliest = $today->modify('+'.LegalVersions::NOTICE_DAYS.' days')->format('Y-m-d');
        if ($version['effective'] < $earliest) {
            throw new \DomainException(\sprintf('%s version %d applies on %s: less than %d days from today, too late to announce.', $page, $version['number'], $version['effective'], LegalVersions::NOTICE_DAYS));
        }
        // The email links the new text (`?v=next`) in the reader's language.
        $missing = $this->pages->missingNextTexts($page);
        if ([] !== $missing) {
            throw new \DomainException(\sprintf('%s version %d has no new text to link: translations/%s missing.', $page, $version['number'], implode(', ', array_map(static fn (string $l): string => "{$page}_next.{$l}.yaml", $missing))));
        }

        /** @var list<array{id: int|string, email: string, display_name: string, locale: string|null}> $rows */
        $rows = $this->db->fetchAllAssociative(
            'SELECT u.id, u.email, u.display_name, u.locale FROM users u
              WHERE (u.email_verified = true OR u.last_login_at IS NOT NULL)
                AND NOT EXISTS (SELECT 1 FROM legal_notice_sent s WHERE s.page = :page AND s.version = :v AND s.user_id = u.id)
              ORDER BY u.id',
            ['page' => $page, 'v' => $version['number']],
        );

        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $locale = \in_array($row['locale'], self::LOCALES, true) ? (string) $row['locale'] : 'en';
            try {
                $this->mailer->send($this->email($page, $version, $row, $locale));
            } catch (TransportExceptionInterface $e) {
                // By id: the message of a refusal can repeat the address.
                $this->logger->warning('Legal change notice refused by the mail server; the next run tries again.', [
                    'user_id' => (int) $row['id'],
                    'page' => $page,
                    'version' => $version['number'],
                    'exception_class' => $e::class,
                ]);
                ++$failed;
                continue;
            }
            $this->db->executeStatement(
                'INSERT INTO legal_notice_sent (page, version, user_id, sent_at) VALUES (:page, :v, :u, NOW()) ON CONFLICT DO NOTHING',
                ['page' => $page, 'v' => $version['number'], 'u' => (int) $row['id']],
            );
            ++$sent;
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * @param array{number: int, date: string, effective: string, significant: bool, changes: list<string>} $version
     * @param array{id: int|string, email: string, display_name: string, locale: string|null}               $row
     */
    private function email(string $page, array $version, array $row, string $locale): TemplatedEmail
    {
        $effective = new \DateTimeImmutable($version['effective']);
        $date = (new \IntlDateFormatter($locale, \IntlDateFormatter::LONG, \IntlDateFormatter::NONE))->format($effective);
        $changes = array_map(fn (string $key): string => $this->translator->trans($key, [], $page, $locale), $version['changes']);

        return (new TemplatedEmail())
            ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
            ->to(new Address($row['email'], $row['display_name']))
            ->subject($this->translator->trans("legal_mail.subject_{$page}", ['%date%' => $date], 'messages', $locale))
            ->htmlTemplate('emails/legal_change.html.twig')
            ->context([
                'page' => $page,
                'locale' => $locale,
                'name' => $row['display_name'],
                'date' => $date,
                'changes' => $changes,
                'page_url' => $this->urls->generate($page, ['_locale' => $locale, 'v' => 'next'], UrlGeneratorInterface::ABSOLUTE_URL),
                'settings_url' => $this->urls->generate('settings', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);
    }
}
