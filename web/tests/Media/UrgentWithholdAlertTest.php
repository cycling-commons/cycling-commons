<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\UrgentWithholdBreaker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The operator alert when the auto-withhold circuit breaker opens
 * (docs/specs/photo-uploads.md §6c).
 *
 * The throttle is the point. The flood that opens the breaker keeps arriving
 * afterwards, so an alert per report would be thousands of messages aimed at
 * the one person who has to read them — a second denial of service, delivered
 * by us.
 */
final class UrgentWithholdAlertTest extends KernelTestCase
{
    /**
     * Reads the mailer's own message log rather than assertEmailCount(): the
     * framework's mail assertions collect through the profiler, which has
     * nothing to collect in a kernel test that never makes a request.
     *
     * @return list<MessageEvent>
     */
    private function sent(): array
    {
        return static::getContainer()->get('mailer.message_logger_listener')->getEvents()->getEvents();
    }

    public function testTheFirstRefusalMailsAHumanAndTheFloodBehindItDoesNot(): void
    {
        self::bootKernel();
        $breaker = static::getContainer()->get(UrgentWithholdBreaker::class);

        // Spend the budget. Everything up to here is allowed, so nothing is
        // sent yet.
        $allowed = 0;
        while ($breaker->allowWithhold()) {
            self::assertLessThan(500, ++$allowed, 'the budget must be finite');
        }
        self::assertGreaterThan(0, $allowed);
        self::assertCount(1, $this->sent(), 'exactly one alert when the breaker opens');

        // The attack continues. The operator is not mailed again.
        for ($i = 0; $i < 20; ++$i) {
            self::assertFalse($breaker->allowWithhold());
        }
        $sent = $this->sent();
        self::assertCount(1, $sent, 'still one — the throttle holds under the flood');

        $email = $sent[0]->getMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('circuit breaker is OPEN', (string) $email->getSubject());
        self::assertSame(['development@cyclingcommons.org'], array_map(
            static fn (Address $a): string => $a->getAddress(),
            $email->getTo(),
        ));
        // It has to tell the operator where to go, or it is just an alarm.
        self::assertStringContainsString('/admin/withheld-photos', (string) $email->getTextBody());
    }
}
