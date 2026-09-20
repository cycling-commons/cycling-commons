<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

/*
 * Does ImageMagick actually do what docker/imagemagick-policy.xml says?
 *
 * The image build runs this and fails on any disagreement, and
 * tests/Media/ImageMagickPolicyTest.php runs it again in the dev and CI
 * containers, so a change that opens a coder, a delegate or a resource is
 * noticed where it is made rather than in a log after an incident.
 *
 * Three questions, because one answer is not enough:
 *
 *   1. Is the file still the file we wrote? Every rule is listed below. A rule
 *      added, removed or loosened fails, including one that only looks
 *      harmless, because this is where "just for now" becomes permanent.
 *   2. Is it the only policy? ImageMagick reads several directories and the
 *      last file wins, so a second policy.xml is a silent override.
 *   3. Does the library agree? The file is advice until ImageMagick parses it,
 *      and its parser fails OPEN: on 2026-08-03 a backtick in a comment made
 *      the whole coder allowlist vanish with no error. So every format
 *      ImageMagick knows is probed, and exactly five may be readable.
 *
 * The probe: a junk file with the format's extension. ImageMagick resolves the
 * coder from the extension and applies the policy BEFORE it parses, so a
 * denied format answers "not authorized" while an allowed one gets as far as
 * complaining about the bytes. Formats with no reader at all answer "no decode
 * delegate", which is not a read path and not our business.
 *
 * @see docs/specs/photo-uploads.md §7a
 */

/** The rules, exactly as intended. domain:name-or-pattern=rights-or-value. */
const EXPECTED_RULES = [
    'coder:*=none',
    'coder:{JPEG,PNG,WEBP,HEIC,HEIF,jpeg,png,webp,heic,heif}=read|write',
    'delegate:*=none',
    'filter:*=none',
    'module:{MSL,MVG,PS,EPS,PDF,SVG,URL,XPS,EPHEMERAL,TEXT,SHOW,WIN,PLT}=none',
    'path:@*=none',
    'resource:area=64MP',
    'resource:disk=1GiB',
    'resource:height=30KP',
    'resource:list-length=32',
    'resource:map=1GiB',
    'resource:memory=512MiB',
    'resource:thread=2',
    // Per image on ImageMagick 7, which this image runs. The hosts run 6, where
    // the same rule is charged to the process and is dropped at install
    // (operations.md 3).
    'resource:time=60',
    'resource:width=30KP',
    'undefined:*=none',
];

/** The only formats a rider photo is ever read from (PhotoProcessor accepts the same five). */
const READABLE = ['HEIC', 'HEIF', 'JPEG', 'PNG', 'WEBP'];

/** Where a second policy file would quietly win. */
const OTHER_POLICY_DIRS = [
    '/usr/local/etc/ImageMagick-7',
    '/usr/local/share/ImageMagick-7',
    '/usr/share/ImageMagick-7',
    '/etc/ImageMagick-6',
    '/root/.config/ImageMagick',
    '/var/www/.config/ImageMagick',
];

$policyFile = $argv[1] ?? '/etc/ImageMagick-7/policy.xml';
$problems = [];

// ── 1. The rules, as written ────────────────────────────────────────────────
$xml = @file_get_contents($policyFile);
if (!\is_string($xml) || '' === trim($xml)) {
    fwrite(\STDERR, "ImageMagick policy: {$policyFile} is missing or empty.\n");
    exit(1);
}
if (str_contains($xml, '`')) {
    // The 2026-08-03 trap: ImageMagick's own parser reads a backtick as a
    // quote and drops the rest of the file, comments included.
    $problems[] = 'policy.xml contains a backtick, which silently disables every rule after it.';
}

$dom = new DOMDocument();
if (!@$dom->loadXML($xml)) {
    fwrite(\STDERR, "ImageMagick policy: {$policyFile} is not well-formed XML.\n");
    exit(1);
}

$found = [];
/** @var DOMElement $node */
foreach ($dom->getElementsByTagName('policy') as $node) {
    $domain = strtolower($node->getAttribute('domain'));
    $key = '' !== $node->getAttribute('name') ? $node->getAttribute('name') : ($node->getAttribute('pattern') ?: '*');
    $value = '' !== $node->getAttribute('rights') ? $node->getAttribute('rights') : $node->getAttribute('value');
    $found[] = $domain.':'.$key.'='.$value;
}
sort($found);
$expected = EXPECTED_RULES;
sort($expected);

foreach (array_diff($found, $expected) as $rule) {
    $problems[] = "a rule this check does not know about: {$rule}";
}
foreach (array_diff($expected, $found) as $rule) {
    $problems[] = "a rule that should be there is gone or changed: {$rule}";
}

// ── 2. The only policy ──────────────────────────────────────────────────────
foreach (OTHER_POLICY_DIRS as $dir) {
    $other = $dir.'/policy.xml';
    if (is_file($other)) {
        $problems[] = "a second policy file would override ours: {$other}";
    }
}
$configurePath = getenv('MAGICK_CONFIGURE_PATH');
if (\is_string($configurePath) && '' !== $configurePath) {
    $problems[] = "MAGICK_CONFIGURE_PATH is set ({$configurePath}), which can point ImageMagick at another policy.";
}

// ── 3. What the library actually allows ─────────────────────────────────────
if (!class_exists(Imagick::class)) {
    fwrite(\STDERR, "ImageMagick policy: ext-imagick is not loaded, so nothing could be verified.\n");
    exit(1);
}

$readable = [];
foreach (Imagick::queryFormats('*') as $format) {
    $path = sys_get_temp_dir().'/cc-policy-probe.'.strtolower($format);
    if (false === @file_put_contents($path, 'not an image at all')) {
        continue;
    }
    try {
        (new Imagick())->readImage($path);
        $readable[] = $format;   // junk read as an image: readable, and then some.
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $denied = str_contains($message, 'not authorized') || str_contains($message, 'security policy');
        $noReader = str_contains($message, 'no decode delegate');
        if (!$denied && !$noReader) {
            $readable[] = $format;   // it got far enough to parse: the coder is open.
        }
    } finally {
        @unlink($path);
    }
}
sort($readable);
$allowed = READABLE;
// HEIF is the one name the library may not register at all: ImageMagick
// 6.9.11 (bookworm) knows HEIC only, 6.9.12 (the hosts) and 7 (this image) both. The
// policy opens both, the app needs HEIC (PhotoProcessor::heicSupported), and a
// .heif on 6.9.11 is a clear photo_format refusal, not a hole. So HEIF is
// required only where it exists; HEIC is required everywhere.
if ([] === Imagick::queryFormats('HEIF')) {
    $allowed = array_values(array_diff($allowed, ['HEIF']));
}
sort($allowed);

foreach (array_diff($readable, $allowed) as $format) {
    $problems[] = "ImageMagick still reads {$format}, which the policy does not allow.";
}
foreach (array_diff($allowed, $readable) as $format) {
    $problems[] = "ImageMagick no longer reads {$format}, which rider photos need.";
}

// A real file of an allowed format must still decode: a policy that refuses
// everything passes every deny check and breaks every upload.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
try {
    (new Imagick())->readImageBlob((string) $png);
} catch (Throwable $e) {
    $problems[] = 'a valid PNG does not decode: '.$e->getMessage();
}

// And what the app WRITES must encode to a blob, spelled the way the app
// spells it (PhotoProcessor: setImageFormat('webp'); ScreenshotStore: 'png').
// ImageMagick 6 checks the blob write under that lower-case name, so an allow
// list in upper case only passes every read probe above and still fails every
// upload, with "empty or invalid image" and no policy message (2026-09-20).
// HEIC and HEIF are read only: libheif decodes them, nothing here encodes them.
foreach (['PNG', 'WEBP'] as $format) {
    try {
        $image = new Imagick();
        $image->readImageBlob((string) $png);
        $image->setImageFormat(strtolower($format));
        if ('' === $image->getImageBlob()) {
            $problems[] = "encoding to {$format} gives an empty blob.";
        }
    } catch (Throwable $e) {
        $problems[] = "cannot encode to {$format} as a blob, which PhotoProcessor needs: ".$e->getMessage();
    }
}

// ── The verdict ─────────────────────────────────────────────────────────────
if ([] !== $problems) {
    fwrite(\STDERR, "ImageMagick policy has drifted from docker/imagemagick-policy.xml:\n");
    foreach ($problems as $problem) {
        fwrite(\STDERR, '  - '.$problem."\n");
    }
    fwrite(\STDERR, "\nIf the change is wanted, update docker/imagemagick-policy.xml AND this check together.\n");
    exit(1);
}

printf(
    "ImageMagick policy verified: %d rules as written, %d formats readable (%s), everything else refused.\n",
    \count($expected),
    \count($readable),
    implode(', ', $readable),
);
