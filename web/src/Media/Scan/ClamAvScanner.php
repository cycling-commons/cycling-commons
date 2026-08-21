<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Scan;

use Psr\Log\LoggerInterface;

/**
 * ClamAV via clamd INSTREAM, else clamscan. CLAMAV_REQUIRED: miss throws, never clean.
 *
 * @see docs/specs/media-storage-architecture.md §3.1
 *
 * @api
 */
final class ClamAvScanner implements VirusScannerInterface
{
    private const int CHUNK_BYTES = 1 << 20;
    private const int TIMEOUT_S = 30;

    public function __construct(
        private readonly string $tcpAddr,
        private readonly bool $required,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function scan(mixed $bytes): ScanVerdict
    {
        try {
            if ('' !== $this->tcpAddr) {
                return $this->scanInstream($bytes);
            }

            return $this->scanBinary($bytes);
        } catch (ScannerUnavailable $e) {
            if ($this->required) {
                throw $e;
            }
            $this->logger->warning('Virus scan SKIPPED ({reason}) - CLAMAV_REQUIRED is off, so this environment accepts unscanned media.', ['reason' => $e->getMessage()]);

            return ScanVerdict::skipped();
        }
    }

    /** @param resource|string $bytes */
    private function scanInstream(mixed $bytes): ScanVerdict
    {
        $socket = @stream_socket_client('tcp://'.$this->tcpAddr, $errno, $error, self::TIMEOUT_S);
        if (false === $socket) {
            throw new ScannerUnavailable(\sprintf('clamd at %s unreachable: %s', $this->tcpAddr, $error ?: ('errno '.$errno)));
        }

        try {
            stream_set_timeout($socket, self::TIMEOUT_S);
            fwrite($socket, "zINSTREAM\0");
            if (\is_string($bytes)) {
                foreach (str_split($bytes, self::CHUNK_BYTES) as $chunk) {
                    fwrite($socket, pack('N', \strlen($chunk)).$chunk);
                }
            } else {
                while ('' !== ($chunk = (string) fread($bytes, self::CHUNK_BYTES))) {
                    fwrite($socket, pack('N', \strlen($chunk)).$chunk);
                    if (feof($bytes)) {
                        break;
                    }
                }
            }
            fwrite($socket, pack('N', 0));

            $reply = trim((string) stream_get_contents($socket), "\0\n ");
        } finally {
            fclose($socket);
        }

        return $this->verdictFrom($reply, 'clamd');
    }

    /** @param resource|string $bytes */
    private function scanBinary(mixed $bytes): ScanVerdict
    {
        $binary = null;
        $path = getenv('PATH') ?: '';
        foreach (['clamscan', 'clamdscan'] as $candidate) {
            foreach (explode(\PATH_SEPARATOR, $path) as $dir) {
                if ('' !== $dir && is_executable($dir.\DIRECTORY_SEPARATOR.$candidate)) {
                    $binary = $candidate;
                    break 2;
                }
            }
        }
        if (null === $binary) {
            throw new ScannerUnavailable('no CLAMAV_TCP_ADDR and no clamscan/clamdscan binary on PATH');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ccscan');
        if (false === $tmp) {
            throw new ScannerUnavailable('cannot create a temp file for the binary scan');
        }
        try {
            file_put_contents($tmp, \is_string($bytes) ? $bytes : (string) stream_get_contents($bytes));
            $out = [];
            $code = 1;
            exec(\sprintf('%s --no-summary %s 2>&1', $binary, escapeshellarg($tmp)), $out, $code);

            // clamscan: 0 clean, 1 infected, anything else = error.
            return match ($code) {
                0 => ScanVerdict::clean(),
                1 => ScanVerdict::infected($this->signatureFrom(implode("\n", $out))),
                default => throw new ScannerUnavailable(\sprintf('%s exited %d: %s', $binary, $code, implode(' | ', \array_slice($out, 0, 3)))),
            };
        } finally {
            @unlink($tmp);
        }
    }

    private function verdictFrom(string $reply, string $via): ScanVerdict
    {
        if (str_ends_with($reply, 'OK')) {
            return ScanVerdict::clean();
        }
        if (str_ends_with($reply, 'FOUND')) {
            return ScanVerdict::infected($this->signatureFrom($reply));
        }

        throw new ScannerUnavailable(\sprintf('%s answered without a verdict: "%s"', $via, $reply));
    }

    private function signatureFrom(string $reply): string
    {
        return trim((string) preg_replace('/^.*?:\s*/', '', (string) preg_replace('/\s*FOUND$/', '', $reply)));
    }
}
