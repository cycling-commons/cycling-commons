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
 * Mail on production 500s. 4xx never alerts; one mail per fault per hour.
 *
 * @see docs/specs/operations.md §2
 *
 * @api
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
        return [KernelEvents::EXCEPTION => ['onException', -128]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();

        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
            return;
        }
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
