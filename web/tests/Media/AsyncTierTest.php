<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Message\ScanAndReleaseUpload;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\SentStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The async tier itself (media plan task 1): the bus exists, and the media
 * message is ROUTED to the async transport rather than handled inline.
 *
 * In test that transport is in-memory, which is the whole point: the media
 * flow is asynchronous, so a suite that handled every dispatch inline could
 * never observe the state the architecture exists to create - received,
 * quarantined, nothing published. Tests drain the transport deliberately
 * (MediaUploadEndpointTest::drain), which is also what makes "a redelivered
 * message changes nothing" testable.
 */
final class AsyncTierTest extends KernelTestCase
{
    public function testAMediaMessageIsRoutedToTheAsyncTransport(): void
    {
        self::bootKernel();
        /** @var MessageBusInterface $bus */
        $bus = static::getContainer()->get(MessageBusInterface::class);

        $message = new ScanAndReleaseUpload('00000000-0000-4000-8000-000000000000', 50.5, 5.9);
        $envelope = $bus->dispatch($message);

        $sent = $envelope->all(SentStamp::class);
        self::assertCount(1, $sent, 'the media message must go to a transport, not to an inline handler');
        self::assertSame('async', $sent[0]->getSenderAlias());

        /** @var InMemoryTransport $transport */
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertSame([$message], array_map(
            static fn ($e) => $e->getMessage(),
            $transport->getSent(),
        ));
    }
}
