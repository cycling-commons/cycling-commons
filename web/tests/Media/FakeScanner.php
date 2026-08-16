<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\Scan\ScannerUnavailable;
use App\Media\Scan\ScanVerdict;
use App\Media\Scan\VirusScannerInterface;

/**
 * The suite's scanner. Clean by default, and switchable to the two answers the
 * release gate has to handle differently: an infected verdict, which is
 * terminal, and an unavailable scanner, which must NOT be
 * (docs/specs/media-storage-architecture.md §3.1).
 *
 * ClamAvScanner itself is pinned by its own unit test against the real
 * protocol. What is under test here is what the HANDLER does with each answer,
 * and that must be provable without a daemon: a suite that needed one would
 * skip exactly the assertions that matter on the machines that have none.
 *
 * @api Wired over VirusScannerInterface in config/packages/test/services.yaml.
 */
final class FakeScanner implements VirusScannerInterface
{
    public ?string $infectedWith = null;
    public bool $unavailable = false;
    public bool $skipped = false;
    public int $calls = 0;

    #[\Override]
    public function scan(mixed $bytes): ScanVerdict
    {
        ++$this->calls;

        if ($this->unavailable) {
            throw new ScannerUnavailable('the suite asked for an unreachable scanner');
        }
        if (null !== $this->infectedWith) {
            return ScanVerdict::infected($this->infectedWith);
        }

        return $this->skipped ? ScanVerdict::skipped() : ScanVerdict::clean();
    }

    public function reset(): void
    {
        $this->infectedWith = null;
        $this->unavailable = false;
        $this->skipped = false;
        $this->calls = 0;
    }
}
