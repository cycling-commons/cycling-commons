<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Contribution;

use App\Service\ContributionStubService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContributionStubServiceTest extends TestCase
{
    public function testSubmitReturnsReceiptWithExpectedShape(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $service = new ContributionStubService($logger);
        $receipt = $service->submit('vote', ['x' => 1], null);

        $this->assertFalse($receipt->persisted);
        $this->assertNotEmpty($receipt->reference);
        $this->assertStringStartsWith('CC-', $receipt->reference);
        $this->assertSame('vote', $receipt->kind);
        $this->assertInstanceOf(\DateTimeImmutable::class, $receipt->submittedAt);
    }
}
