<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Media\AlertRecipients;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Tells somebody when the site throws a 500.
 *
 * The app ships no logging bundle, so before this an unhandled exception in
 * production went to the container's stderr and nowhere else: nothing was
 * watching, and the first anyone would know of a broken deploy was a rider
 * writing in. This is the smallest thing that fixes that, and it deliberately
 * adds no dependency — it reuses the mailer and the `app.alert_emails` address
 * that the photo circuit breaker and the escalation path already alert to.
 *
 * It is NOT a replacement for real error tracking. There is no grouping, no
 * release correlation and no history; a Sentry-class tool would give all three.
 * It is what makes the gap survivable until one is chosen (docs/specs/
 * operations.md), and it is written so that dropping such a tool in later means
 * deleting this class, not unpicking it.
 *
 * Three rules:
 *
 * - **4xx never alerts.** A 404 or a rejected CSRF token is the application
 *   working. Only a non-HTTP exception, or a 5xx, is ours.
 * - **Throttled hard.** One mail per exception signature per hour, through the
 *   shared cache — a failure on a hot path throws thousands of times a minute,
 *   and a mailbox with 4,000 copies of one bug in it is the same as no alert
 *   at all. The signature is class + file + line, so two different faults still
 *   both get through.
 * - **It cannot make things worse.** Every failure inside here is swallowed and
 *   logged. An alerting path that throws while handling an exception replaces a
 *   500 the reader could understand with one nobody can.
 *
 * @api Auto-registered event subscriber.
 */
final readonly class ServerErrorAlertSubscriber implements EventSubscriberInterface
{
    /** One alert per distinct fault per hour. */
    private const int THROTTLE_SECONDS = 3600;

    public function __construct(
        private MailerInterface $mailer,
        private AlertRecipients $recipients,
        private LoggerInterface $logger,
        private CacheInterface $cache,
        #[Autowire('%env(APP_SITE_URL)%')]
        private string $siteUrl,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        // Late, so anything that converts an exception into a proper response
        // (the firewall's access-denied handling, for one) has already had its
        // turn and we are only seeing what really failed.
        return [KernelEvents::EXCEPTION => ['onException', -128]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();

        // The application answering "no" is not a fault.
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return;
        }
        // Dev and test throw on purpose, constantly. Alerting there would train
        // everyone to ignore the alert.
        if ('prod' !== $this->environment) {
            return;
        }

        try {
            $signature = hash('xxh128', $e::class.'|'.$e->getFile().'|'.$e->getLine());
            $this->cache->get('error_alert.'.$signature, function (ItemInterface $item) use ($e, $event): true {
                $item->expiresAfter(self::THROTTLE_SECONDS);
                $this->send($e, $event);

                return true;
            });
        } catch (\Throwable $inner) {
            // Never let the alerting path change what the reader sees.
            $this->logger->error('Could not alert on a server error.', [
                'alerting_error' => $inner->getMessage(),
                'original' => $e->getMessage(),
            ]);
        }
    }

    private function send(\Throwable $e, ExceptionEvent $event): void
    {
        $to = $this->recipients->all();
        if ([] === $to) {
            $this->logger->critical('A server error occurred and no alert recipients are configured.', [
                'exception' => $e::class,
            ]);

            return;
        }

        $request = $event->getRequest();

        /* What the mail carries is deliberately bounded. The path and the
           exception's own location are what point a developer at the fault;
           the request body, the session and the query string are not included,
           because they routinely hold personal data and this is an email. */
        $body = implode("\n", [
            $e::class,
            $e->getMessage(),
            '',
            'at '.$e->getFile().':'.$e->getLine(),
            'route   '.(string) $request->attributes->get('_route', '(none)'),
            'path    '.$request->getPathInfo(),
            'method  '.$request->getMethod(),
            '',
            'Only the first occurrence in an hour is sent — check the logs for how',
            'often this is really happening.',
            '',
            $this->siteUrl,
        ]);

        try {
            $this->mailer->send(
                (new Email())
                    ->from(new Address('noreply@cyclingcommons.org', 'Cycling Commons'))
                    ->to(...$to)
                    ->subject('[Cycling Commons] 500 — '.$e::class)
                    ->text($body)
            );
        } catch (TransportExceptionInterface $t) {
            $this->logger->critical('A server error occurred and the alert mail could not be sent.', [
                'exception' => $e::class,
                'transport_error' => $t->getMessage(),
            ]);
        }
    }
}
