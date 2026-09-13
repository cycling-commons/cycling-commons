<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support\DependencyInjection;

use App\Support\DependencyInjection\ConsumerNameEnvVarProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsumerNameEnvVarProcessor::class)]
final class ConsumerNameEnvVarProcessorTest extends TestCase
{
    public function testTheNameCarriesTheProcessIdSoTwoWorkersNeverShareOne(): void
    {
        $name = (new ConsumerNameEnvVarProcessor())->getEnv('consumer_name', 'MESSENGER_CONSUMER', static fn (): string => '');

        self::assertStringEndsWith('-'.getmypid(), $name, 'the process id is what makes it unique');
        self::assertStringStartsWith('worker-', $name, 'unset falls back to a readable prefix');
    }

    public function testAnUnsetVariableIsNormalAndNotAnError(): void
    {
        $name = (new ConsumerNameEnvVarProcessor())->getEnv(
            'consumer_name',
            'MESSENGER_CONSUMER',
            // What Symfony does for a variable nobody defined. A worker must
            // still start, so the throw is caught rather than declared.
            static fn (): string => throw new \RuntimeException('Environment variable not found.'),
        );

        self::assertStringEndsWith('-'.getmypid(), $name);
    }

    public function testAConfiguredPrefixIsKeptSoFleetsStayTellableApart(): void
    {
        $name = (new ConsumerNameEnvVarProcessor())->getEnv('consumer_name', 'MESSENGER_CONSUMER', static fn (): string => 'media');

        self::assertStringStartsWith('media-', $name);
        self::assertStringEndsWith('-'.getmypid(), $name);
    }

    public function testTheProcessorAnswersToTheNameTheConfigUses(): void
    {
        self::assertArrayHasKey('consumer_name', ConsumerNameEnvVarProcessor::getProvidedTypes());
    }
}
