<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Support;

use App\Support\Entity\BugScreenshot;
use App\Support\ScreenshotRejected;
use App\Support\ScreenshotStore;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A picture whose PNG comes out over the stored-size cap is shrunk until it
 * fits, not refused. Random noise stands in for a busy screenshot: it does
 * not compress, so its PNG size is predictable (about 3 bytes a pixel).
 */
final class ScreenshotFitTest extends KernelTestCase
{
    public function testAPictureOverTheStoredCapIsShrunkUntilItFits(): void
    {
        // 2000x1125 noise: about 6.4 MB as PNG, under the 8 MB upload cap
        // and far over the 2 MB stored cap.
        $stored = $this->store()->render($this->noise(2000, 1125));

        self::assertLessThanOrEqual(BugScreenshot::MAX_BYTES, \strlen($stored->bytes));
        self::assertLessThan(2000, $stored->width);
        self::assertGreaterThanOrEqual(1000, $stored->width, 'never below MIN_LONG_SIDE');
    }

    public function testAPictureThatCannotFitEvenAtTheSmallestSizeIsRefusedWithItsOwnMessage(): void
    {
        // A 100 kB cap: no amount of shrinking down to 1000px gets noise there.
        try {
            $this->store()->render($this->noise(1400, 1400), 'png', 100_000);
            self::fail('expected a refusal');
        } catch (ScreenshotRejected $e) {
            self::assertSame('support.bug.error.shot_too_detailed', $e->translationKey());
        }
    }

    private function store(): ScreenshotStore
    {
        self::bootKernel();

        return self::getContainer()->get(ScreenshotStore::class);
    }

    private function noise(int $width, int $height): string
    {
        $image = new \Imagick();
        $image->newImage($width, $height, 'gray');
        $image->addNoiseImage(\Imagick::NOISE_RANDOM);
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }
}
