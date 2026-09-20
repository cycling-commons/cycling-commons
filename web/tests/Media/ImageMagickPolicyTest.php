<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\Media;

use PHPUnit\Framework\TestCase;

/**
 * The ImageMagick policy is the one this project wrote, and ImageMagick agrees
 * with it (docs/specs/photo-uploads.md §7a).
 *
 * The image build runs the same script, so this is the second place to notice:
 * a policy edited in a running container, an image built elsewhere, or a base
 * image that ships its own policy.xml all show up here, in a test run, rather
 * than in a log after an incident.
 *
 * Skipped where there is nothing to check: a machine with no ext-imagick and
 * no policy file is not this app's container.
 */
final class ImageMagickPolicyTest extends TestCase
{
    private const string POLICY = '/etc/ImageMagick-7/policy.xml';

    public function testImageMagickAllowsExactlyWhatThePolicySays(): void
    {
        if (!class_exists(\Imagick::class) || !is_file(self::POLICY)) {
            self::markTestSkipped('ext-imagick and '.self::POLICY.' are the container this checks.');
        }

        $script = \dirname(__DIR__, 2).'/docker/verify-imagemagick-policy.php';
        self::assertFileExists($script);

        $output = [];
        $status = 0;
        exec(escapeshellarg(\PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertStringContainsString('everything else refused', implode("\n", $output));
    }
}
