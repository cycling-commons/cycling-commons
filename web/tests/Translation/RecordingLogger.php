<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Translation;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    public function hasWarningThatContains(string $needle): bool
    {
        foreach ($this->records as $r) {
            if ('warning' === $r['level'] && (str_contains($r['message'], $needle) || str_contains(json_encode($r['context'], \JSON_THROW_ON_ERROR), $needle))) {
                return true;
            }
        }

        return false;
    }
}
