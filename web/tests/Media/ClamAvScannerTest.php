<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Scan\ClamAvScanner;
use App\Media\Scan\ScannerUnavailable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The scanner's three outcomes, with the fail-closed one first: an
 * unreachable daemon under CLAMAV_REQUIRED must THROW, never answer clean -
 * a fail-open scanner is indistinguishable from a working one until the day
 * it matters. Protocol verdicts are tested against a fake clamd speaking
 * real INSTREAM framing in a child process, so no test needs ClamAV
 * installed.
 */
final class ClamAvScannerTest extends TestCase
{
    /** @var resource|null */
    private $server;

    #[\Override]
    protected function tearDown(): void
    {
        if (\is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
    }

    /** Starts a one-shot fake clamd; returns its addr. */
    private function fakeClamd(string $verdictLine): string
    {
        $port = random_int(20000, 40000);
        $script = <<<'PHP'
            $srv = stream_socket_server('tcp://127.0.0.1:'.$argv[1], $e1, $e2);
            if (!$srv) { fwrite(STDERR, "bind failed\n"); exit(1); }
            fwrite(STDOUT, "ready\n");
            $c = stream_socket_accept($srv, 10);
            // zINSTREAM\0, then length-framed chunks until the zero frame.
            stream_get_line($c, 64, "\0");
            while (true) {
                $head = fread($c, 4);
                if ($head === false || strlen($head) < 4) break;
                $len = unpack('N', $head)[1];
                if ($len === 0) break;
                $left = $len;
                while ($left > 0) { $b = fread($c, $left); if ($b === false || $b === '') break 2; $left -= strlen($b); }
            }
            fwrite($c, $argv[2] . "\0");
            fclose($c);
            PHP;
        $proc = proc_open(
            ['php', '-r', $script, (string) $port, $verdictLine],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($proc);
        $this->server = $proc;
        // Wait for the bind before connecting, or the test races the child.
        self::assertSame("ready\n", fgets($pipes[1]));

        return '127.0.0.1:'.$port;
    }

    public function testRequiredAndUnreachableThrowsRatherThanPassing(): void
    {
        $scanner = new ClamAvScanner('127.0.0.1:1', true, new NullLogger());

        $this->expectException(ScannerUnavailable::class);
        $scanner->scan('bytes');
    }

    public function testNotRequiredAndUnreachableIsASkippedVerdictNotAClean(): void
    {
        $scanner = new ClamAvScanner('127.0.0.1:1', false, new NullLogger());

        $verdict = $scanner->scan('bytes');

        self::assertFalse($verdict->infected);
        self::assertTrue($verdict->skipped, 'a no-scanner pass must be marked skipped, never a real clean');
    }

    public function testACleanStreamIsClean(): void
    {
        $scanner = new ClamAvScanner($this->fakeClamd('stream: OK'), true, new NullLogger());

        $verdict = $scanner->scan('just a photo');

        self::assertFalse($verdict->infected);
        self::assertFalse($verdict->skipped);
    }

    public function testAFoundReplyIsInfectedWithItsSignature(): void
    {
        $scanner = new ClamAvScanner($this->fakeClamd('stream: Eicar-Signature FOUND'), true, new NullLogger());

        $verdict = $scanner->scan('X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR');

        self::assertTrue($verdict->infected);
        self::assertSame('Eicar-Signature', $verdict->signature);
    }

    public function testANonVerdictReplyIsUnavailableNotClean(): void
    {
        $scanner = new ClamAvScanner($this->fakeClamd('INSTREAM size limit exceeded. ERROR'), true, new NullLogger());

        $this->expectException(ScannerUnavailable::class);
        $scanner->scan('bytes');
    }
}
