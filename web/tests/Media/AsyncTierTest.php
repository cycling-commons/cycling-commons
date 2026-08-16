<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Message\ScanAndReleaseUpload;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The async tier itself (media plan task 1): the bus exists, the media
 * message routes, and in the test environment the transport is sync:// so a
 * dispatch is handled inline - hermetic, no consumer, and rolled back with
 * the DAMA transaction like everything else.
 */
final class AsyncTierTest extends KernelTestCase
{
    public function testAMediaMessageRoundTripsThroughTheBus(): void
    {
        self::bootKernel();
        /** @var MessageBusInterface $bus */
        $bus = static::getContainer()->get(MessageBusInterface::class);

        $envelope = $bus->dispatch(new ScanAndReleaseUpload('00000000-0000-4000-8000-000000000000', 50.5, 5.9));

        self::assertCount(1, $envelope->all(HandledStamp::class),
            'the sync test transport must hand the message to exactly one handler');
    }
}
