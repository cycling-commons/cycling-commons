<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Support;

use App\Media\Scan\ScannerUnavailable;
use App\Media\Scan\VirusScannerInterface;
use App\Support\Entity\BugScreenshot;
use Psr\Log\LoggerInterface;

/**
 * Turn whatever was pasted into a screenshot we are willing to keep.
 *
 * A bug report accepts an image from a stranger. That is the same risk any
 * upload carries, met the same way {@see \App\Media\PhotoProcessor} meets it:
 * **decode it and draw it again**. Whatever the original file was (a polyglot,
 * an SVG with script in it, a JPEG with a payload after the end marker), what
 * ends up in the database is a fresh PNG that this server's Imagick wrote from
 * a pixel buffer. Nothing survives that round trip except the picture.
 *
 * Three limits, in this order, because each one only makes sense once the
 * previous has passed:
 *
 * 1. **Bytes in.** {@see MAX_UPLOAD_BYTES}, before anything is decoded.
 * 2. **Pixels.** {@see MAX_PIXELS}, read from the header by `ping`. A 900-byte
 *    file can claim 40000x40000 and eat the machine on decode; the byte cap
 *    does not bound the decoded size, so this is a separate check.
 * 3. **Bytes out.** {@see BugScreenshot::MAX_BYTES}, after
 *    re-encoding, because a screenshot of a photograph re-encodes larger than
 *    a screenshot of a form.
 *
 * Output is PNG for a screenshot: flat colour, sharp text and straight edges,
 * which is exactly what PNG is good at and exactly what JPEG smears. The
 * curator room asks {@see render()} for WebP instead, because its pictures
 * are photographs as often as screens.
 *
 * **The virus scanner runs too**, on the bytes as uploaded, before Imagick sees
 * them. Rider photographs get scanned ({@see \App\Media\Scan\ClamAvScanner}),
 * and a screenshot is a file from a stranger in exactly the same way, so it
 * would be odd for one door to be guarded and the other not.
 *
 * The two defences catch different things and neither replaces the other: the
 * scanner knows named malware and the re-encode destroys anything the scanner
 * has never heard of. Order matters. Scan first, so ClamAV sees the file the
 * reporter actually sent rather than the PNG we drew from it.
 *
 * There is NO quarantine, unlike the media pipeline. Quarantine exists there
 * because a rider photograph gets a public URL and must not be reachable until
 * it is cleared. A bug screenshot never gets one: it is served to curators, as
 * an attachment, from the database. There is nothing to hold it back from.
 *
 * @see docs/specs/contact-and-support.md §6
 *
 * @api
 */
final class ScreenshotStore
{
    public function __construct(
        private readonly VirusScannerInterface $scanner,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Before decoding. Generous: a full-page 4K screenshot is legitimately large. */
    public const int MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

    /** Against decompression bombs. Same reasoning as PhotoProcessor::MAX_PIXELS. */
    private const int MAX_PIXELS = 40_000_000;

    /** Wide enough that text stays readable, small enough to store. */
    private const int MAX_LONG_SIDE = 2000;

    private const array ALLOWED_FORMATS = ['PNG', 'JPEG', 'GIF', 'WEBP'];

    private const array RESOURCE_LIMITS = [
        \Imagick::RESOURCETYPE_WIDTH => 30_000,
        \Imagick::RESOURCETYPE_HEIGHT => 30_000,
    ];

    /**
     * @throws ScreenshotRejected with a translation key, for anything a person
     *                            can act on: too big, not an image, unreadable
     */
    public function accept(string $bytes): BugScreenshot
    {
        $image = $this->render($bytes);

        return new BugScreenshot($image->mimeType, $image->bytes, $image->width, $image->height);
    }

    /**
     * The same defences for any caller: scan, refuse the bomb, draw it again.
     *
     * `$as` is what comes out: `png` for a screenshot (flat colour, sharp
     * text), `webp` for a photograph (a curator's picture of a place, which
     * PNG would store at four times the size). `$maxBytes` bounds the result.
     *
     * @throws ScreenshotRejected with a translation key
     */
    public function render(string $bytes, string $as = 'png', int $maxBytes = BugScreenshot::MAX_BYTES): StoredImage
    {
        if ('' === $bytes) {
            throw new ScreenshotRejected('support.bug.error.shot_empty');
        }
        if (\strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new ScreenshotRejected('support.bug.error.shot_too_large');
        }

        $this->refuseIfInfected($bytes);

        foreach (self::RESOURCE_LIMITS as $type => $limit) {
            \Imagick::setResourceLimit($type, $limit);
        }

        // Header first: refuse the bomb before a decoder ever allocates for it.
        $ping = new \Imagick();
        try {
            $ping->pingImageBlob($bytes);
            $format = strtoupper($ping->getImageFormat());
            $width = $ping->getImageWidth();
            $height = $ping->getImageHeight();
        } catch (\ImagickException) {
            throw new ScreenshotRejected('support.bug.error.shot_unreadable');
        } finally {
            $ping->clear();
        }

        if (!\in_array($format, self::ALLOWED_FORMATS, true)) {
            throw new ScreenshotRejected('support.bug.error.shot_format');
        }
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            throw new ScreenshotRejected('support.bug.error.shot_too_large');
        }

        $image = new \Imagick();
        try {
            $image->readImageBlob($bytes);

            // An animated GIF is one image here, not a flipbook. getImage()
            // then takes that one frame out of the list: a list writes as a
            // sequence, which WebP refuses ("failed to get the image contents").
            $image = $image->coalesceImages();
            $image->setFirstIterator();
            $image = $image->getImage();
            $image->setImagePage(0, 0, 0, 0);

            // Screenshots arrive right way up; orientation metadata on one is
            // noise, but honouring it costs nothing and never hurts.
            if (\Imagick::ORIENTATION_TOPLEFT !== $image->getImageOrientation()) {
                $image->autoOrient();
                $image->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
            }

            $long = max($image->getImageWidth(), $image->getImageHeight());
            if ($long > self::MAX_LONG_SIDE) {
                $scale = self::MAX_LONG_SIDE / $long;
                $image->resizeImage(
                    (int) round($image->getImageWidth() * $scale),
                    (int) round($image->getImageHeight() * $scale),
                    \Imagick::FILTER_LANCZOS,
                    1,
                );
            }

            // stripImage() before setting the format: it clears every profile
            // and comment, which is where a screenshot's incidental metadata
            // (the window title, the file path, the tool that took it) lives.
            $image->stripImage();
            $image->setImageFormat('webp' === $as ? 'webp' : 'png');
            $image->setImageCompressionQuality('webp' === $as ? 82 : 90);

            $out = $image->getImageBlob();
            $outWidth = $image->getImageWidth();
            $outHeight = $image->getImageHeight();
        } catch (\ImagickException) {
            throw new ScreenshotRejected('support.bug.error.shot_unreadable');
        } finally {
            $image->clear();
        }

        if (\strlen($out) > $maxBytes) {
            throw new ScreenshotRejected('support.bug.error.shot_too_large');
        }

        return new StoredImage('webp' === $as ? 'image/webp' : 'image/png', $out, $outWidth, $outHeight);
    }

    /**
     * Refuse a file the scanner names, and carry on if the scanner is down.
     *
     * Fail-open is deliberate and is the same call the media pipeline makes
     * without `CLAMAV_REQUIRED`: a scanner outage must not silently close the
     * one door somebody uses to tell us the site is broken. The re-encode still
     * runs, and it is the defence that does not depend on a running daemon.
     */
    private function refuseIfInfected(string $bytes): void
    {
        try {
            $verdict = $this->scanner->scan($bytes);
        } catch (ScannerUnavailable $e) {
            $this->logger->warning('A bug screenshot was stored unscanned: the virus scanner is unavailable.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if ($verdict->infected) {
            $this->logger->warning('A bug screenshot was refused by the virus scanner.', [
                'signature' => $verdict->signature,
            ]);

            throw new ScreenshotRejected('support.bug.error.shot_infected');
        }
    }

    /**
     * Accept a `data:` URL, which is how a pasted screenshot arrives.
     *
     * Ctrl+V in the bug panel gives the page a Blob, and the simplest thing the
     * page can do with it is read it as a data URL and post it as a string.
     * Only base64 image payloads are read; anything else is refused before it
     * is decoded.
     */
    public function acceptDataUrl(string $dataUrl): BugScreenshot
    {
        if (!preg_match('~^data:image/(png|jpeg|jpg|gif|webp);base64,~i', $dataUrl, $m)) {
            throw new ScreenshotRejected('support.bug.error.shot_format');
        }

        $payload = substr($dataUrl, \strlen($m[0]));

        // Cheap guard before decoding: base64 is 4 bytes per 3, so an oversized
        // payload can be refused without ever materialising it.
        if (\strlen($payload) > (int) (self::MAX_UPLOAD_BYTES * 4 / 3) + 16) {
            throw new ScreenshotRejected('support.bug.error.shot_too_large');
        }

        $bytes = base64_decode($payload, true);
        if (false === $bytes) {
            throw new ScreenshotRejected('support.bug.error.shot_unreadable');
        }

        return $this->accept($bytes);
    }
}
