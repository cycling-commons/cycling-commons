<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Media\Commons;

/**
 * The Wikimedia Commons filename a coverage POI's tags point at, or null.
 *
 * Refusing is the common case. Measured over 487,704 letter-P rows on
 * 2026-08-24: half the `image` tags name a stranger's web server, and most
 * `wikimedia_commons` tags name a category rather than a file. So every branch
 * here is a shape the harvest actually holds, not a hypothetical, and anything
 * unrecognised is refused rather than guessed at.
 *
 * @see docs/specs/coverage-provider.md §7
 *
 * @api
 */
final class CommonsFile
{
    /** Stills only. A PDF or an OGV is a valid Commons file and not a photo. */
    private const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff'];

    /** The tags that can name a file, in the order we trust them. */
    private const array TAGS = ['wikimedia_commons', 'image'];

    /** @param array<string, mixed> $tags */
    public static function fromTags(array $tags): ?string
    {
        foreach (self::TAGS as $key) {
            $raw = $tags[$key] ?? null;
            if (!\is_string($raw) || '' === trim($raw)) {
                continue;
            }
            $file = self::parse(trim($raw));
            if (null !== $file) {
                return $file;
            }
        }

        return null;
    }

    private static function parse(string $raw): ?string
    {
        if (preg_match('~^File:(.+)$~u', $raw, $m)) {
            return self::clean($m[1]);
        }

        // The host must terminate exactly: "commons.wikimedia.org.evil.test" is
        // not Commons, and a prefix match would hand it our fetcher.
        if (preg_match('~^https?://commons\.wikimedia\.org/wiki/File:([^?#]+)~u', $raw, $m)) {
            return self::clean(rawurldecode($m[1]));
        }

        // What 419 catalog rows carry: SeedWikidataPlacesCommand and the
        // Wallonia enrich step wrote Special:FilePath URLs straight into
        // item.attributes->photo, so localising a row means reading the
        // filename back out of one.
        if (preg_match('~^https?://commons\.wikimedia\.org/wiki/Special:FilePath/([^?#]+)~u', $raw, $m)) {
            return self::clean(rawurldecode($m[1]));
        }

        // A thumbnail URL names the file it is a thumbnail OF, one segment on
        // from the hash directories, so the size prefix is never the answer.
        // Two hosts because Wikimedia split thumbnails onto thumb.wikimedia.org;
        // the redirect chain out of Special:FilePath ends there now, and a file
        // reached that way must resolve to the same name as one reached any
        // other way or we would fetch and store it twice.
        if (preg_match('~^https?://(?:upload|thumb)\.wikimedia\.org/wikipedia/commons/thumb/[0-9a-f]/[0-9a-f]{2}/([^/?#]+)~u', $raw, $m)) {
            return self::clean(rawurldecode($m[1]));
        }
        if (preg_match('~^https?://(?:upload|thumb)\.wikimedia\.org/wikipedia/commons/[0-9a-f]/[0-9a-f]{2}/([^/?#]+)~u', $raw, $m)) {
            return self::clean(rawurldecode($m[1]));
        }

        return null;
    }

    private static function clean(string $name): ?string
    {
        $name = str_replace('_', ' ', trim($name));
        if ('' === $name || \strlen($name) > 240) {
            return null;
        }

        // No path separators and no control characters: this string becomes an
        // API parameter and, later, part of a URL we hand a rider.
        if (preg_match('~[/\x00-\x1f]~', $name)) {
            return null;
        }

        $ext = strtolower(pathinfo($name, \PATHINFO_EXTENSION));

        return \in_array($ext, self::EXTENSIONS, true) ? $name : null;
    }
}
