<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Service;

use App\EventSubscriber\ServerErrorAlertSubscriber;
use App\Media\AlertRecipients;
use App\Settings\SettingsProviderInterface;
use App\Settings\SettingsRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Production 500 alerts (test-suite review 2026-08-24).
 *
 * This subscriber is the only thing that tells anyone the site is throwing
 * 500s, and it had no test. Every branch that matters is a branch that fails
 * SILENTLY when wrong: alerting from staging, alerting on a 404, mailing the
 * same fault on every request of an outage, or swallowing the outage because
 * the mail transport is the thing that broke.
 *
 * A unit test, not a functional one, because the environment is a constructor
 * argument: 'prod' behaviour is unreachable from a kernel booted in 'test'.
 *
 * @see docs/specs/operations.md §2
 */
final class ServerErrorAlertSubscriberTest extends TestCase
{
    public function testProdServerErrorSendsOneMail(): void
    {
        $mailer = new CollectingMailer();
        $subscriber = self::subscriber($mailer);

        $subscriber->onException(self::event(new \RuntimeException('boom')));

        self::assertCount(1, $mailer->sent);
        $email = $mailer->sent[0];
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('RuntimeException', (string) $email->getSubject());
        $body = (string) $email->getTextBody();
        self::assertStringContainsString('boom', $body);
        // The operator needs to know WHERE, not just what.
        self::assertStringContainsString('path    /explode', $body);
        self::assertStringContainsString('method  GET', $body);
        self::assertSame(['ops@example.test'], array_map(
            static fn ($a): string => $a->getAddress(),
            $email->getTo(),
        ));
    }

    public function testClientErrorNeverAlerts(): void
    {
        $mailer = new CollectingMailer();
        $subscriber = self::subscriber($mailer);

        $subscriber->onException(self::event(new NotFoundHttpException('no such page')));
        $subscriber->onException(self::event(new HttpException(429, 'slow down')));

        // A crawler hitting dead links must not page anyone.
        self::assertSame([], $mailer->sent);
    }

    public function testHttpExceptionAt500StillAlerts(): void
    {
        $mailer = new CollectingMailer();
        $subscriber = self::subscriber($mailer);

        // The 4xx guard is `< 500`, so a deliberately thrown 503 is an outage
        // like any other.
        $subscriber->onException(self::event(new HttpException(503, 'upstream down')));

        self::assertCount(1, $mailer->sent);
    }

    public function testNonProdNeverAlerts(): void
    {
        $mailer = new CollectingMailer();
        $subscriber = self::subscriber($mailer, environment: 'staging');

        $subscriber->onException(self::event(new \RuntimeException('boom')));

        self::assertSame([], $mailer->sent);
    }

    public function testSameFaultIsThrottledWithinTheHour(): void
    {
        $mailer = new CollectingMailer();
        $subscriber = self::subscriber($mailer);

        // One line throwing on every request of an outage is thousands of
        // exceptions and must stay one mail.
        $fault = new \RuntimeException('boom');
        for ($i = 0; $i < 25; ++$i) {
            $subscriber->onException(self::event($fault));
        }

        self::assertCount(1, $mailer->sent);
    }

    public function testADifferentFaultStillAlerts(): void
    {
        $mailer = new CollectingMailer();
        $subscriber = self::subscriber($mailer);

        // The throttle key is class|file|line, so a second, unrelated fault
        // during the same hour is not silenced by the first.
        $subscriber->onException(self::event(new \RuntimeException('boom')));
        $subscriber->onException(self::event(new \LogicException('different')));

        self::assertCount(2, $mailer->sent);
    }

    public function testNoRecipientsLogsCriticalInsteadOfThrowing(): void
    {
        $mailer = new CollectingMailer();
        $logger = new RecordingLogger();
        $subscriber = self::subscriber($mailer, recipients: '', logger: $logger);

        $subscriber->onException(self::event(new \RuntimeException('boom')));

        self::assertSame([], $mailer->sent);
        // Misconfiguration must be loud in the logs, never a silent no-op.
        self::assertContains('critical', $logger->levels);
    }

    public function testATransportFailureIsLoggedAndNotRethrown(): void
    {
        $mailer = new ThrowingMailer();
        $logger = new RecordingLogger();
        $subscriber = self::subscriber($mailer, logger: $logger);

        // The alert path must never replace the original 500 with its own
        // exception: the user's error page comes first.
        $subscriber->onException(self::event(new \RuntimeException('boom')));

        self::assertContains('critical', $logger->levels);
    }

    private static function subscriber(
        MailerInterface $mailer,
        string $environment = 'prod',
        string $recipients = 'ops@example.test',
        ?RecordingLogger $logger = null,
    ): ServerErrorAlertSubscriber {
        return new ServerErrorAlertSubscriber(
            $mailer,
            new AlertRecipients(new FixedSettings($recipients)),
            $logger ?? new RecordingLogger(),
            new ArrayAdapter(),
            'https://example.test',
            $environment,
        );
    }

    private static function event(\Throwable $e): ExceptionEvent
    {
        return new ExceptionEvent(
            new DummyKernel(),
            Request::create('/explode'),
            HttpKernelInterface::MAIN_REQUEST,
            $e,
        );
    }
}

/** @internal */
final class CollectingMailer implements MailerInterface
{
    /** @var list<RawMessage> */
    public array $sent = [];

    #[\Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $this->sent[] = $message;
    }
}

/** @internal */
final class ThrowingMailer implements MailerInterface
{
    #[\Override]
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        throw new TransportException('smtp is down');
    }
}

/** @internal */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $levels = [];

    /** @var list<string> */
    public array $messages = [];

    /** @param array<string, mixed> $context */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->levels[] = (string) $level;
        $this->messages[] = (string) $message;
    }
}

/** @internal */
final readonly class FixedSettings implements SettingsProviderInterface
{
    public function __construct(private string $alertEmails)
    {
    }

    #[\Override]
    public function get(string $key): int
    {
        throw new \InvalidArgumentException($key);
    }

    #[\Override]
    public function getString(string $key): string
    {
        return SettingsRegistry::ALERT_EMAILS === $key
            ? $this->alertEmails
            : throw new \InvalidArgumentException($key);
    }
}

/** @internal */
final class DummyKernel implements HttpKernelInterface
{
    #[\Override]
    public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): \Symfony\Component\HttpFoundation\Response
    {
        throw new \LogicException('not used');
    }
}
