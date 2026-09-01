<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Translation;

use App\Translation\Exception\CatalogueBlockScalarException;
use App\Translation\Exception\CatalogueKeyNotFoundException;
use App\Translation\Exception\CatalogueProtectedKeyException;
use App\Translation\Exception\CatalogueWriteNotOptedInException;
use App\Translation\Exception\CatalogueWriteVerificationException;
use App\Translation\Exception\InvalidLocaleException;
use App\Translation\Exception\NotDevEnvironmentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Writes a single translated value into
 * `web/translations/messages.<locale>.yaml` by editing only the one line
 * that carries it, never by parsing the file into an array and dumping it
 * back out.
 *
 * A round trip through a YAML library was measured on messages.nl.yaml and
 * rejected on evidence: it took the file from 4,445 lines to 4,325, left
 * only 3,099 lines byte-identical (so a one-word change would rewrite
 * about 1,350 lines), stripped all 47 comments including the SPDX header
 * on line 1 (which fails `web/tools/check-spdx.sh`), and flattened the
 * `readme: |` literal block into one enormous quoted line. Every other
 * byte in the file, comments, blank lines, key order, and the trailing
 * newline included, must survive a write untouched.
 *
 * The dotted key is resolved by walking indentation levels rather than by
 * a regex over the whole file, because two different parents can hold a
 * leaf of the same name (`translate.kicker` and `scout.kicker` both exist
 * in every catalogue) and a flat regex has no way to tell them apart.
 *
 * Because a line edit is exactly where silent corruption hides, the write
 * proves its own work before touching disk: it parses the original and
 * the candidate content into arrays and requires them to differ at
 * exactly one key, the one being written, with the new value equal to
 * what was asked for. Anything else throws instead of writing.
 *
 * **Two independent signals gate the write**, not one boolean read twice.
 * The kernel environment must be `dev`, AND `CC_CATALOGUE_WRITE` must be
 * explicitly opted in. `web/.env` commits `APP_ENV=dev` and leaves
 * `CC_CATALOGUE_WRITE` empty, so a release that lost its `APP_ENV`
 * override still cannot write: it would have to lose the override AND
 * carry an opt-in nobody set. See {@see CatalogueWriteNotOptedInException}
 * for what that failure would have cost (translations.md §7.1).
 *
 * @see docs/specs/translations.md §7.1, §7.4
 *
 * @api
 */
final class CatalogueWriter
{
    /**
     * A structural YAML key line: optional leading spaces, then a bare
     * key (letters, digits, underscore, dot, hyphen; catalogue keys never
     * quote or escape their key), then a colon. Everything after the colon
     * is captured whole so the caller can tell an empty tail (a mapping, no
     * value on this line) from a block scalar marker from a quoted value.
     *
     * The hyphen is load-bearing: 74 lines of every catalogue are region
     * labels under `regions:` whose keys carry one (`limburg-nl`,
     * `baden-wurttemberg`, `nordrhein-westfalen`, ...), and those are
     * exactly the strings this tool exists to draft. Without it those lines
     * matched nothing, so they never joined the indentation stack, their
     * `label:` children resolved to the phantom path `regions.label`, and a
     * request for the real key was refused as "that key does not exist",
     * which was false.
     */
    private const string KEY_LINE_PATTERN = '/^(?<indent>[ ]*)(?<key>[A-Za-z0-9_.-]+):(?<tail>.*)$/';

    private const string BLOCK_SCALAR_PATTERN = '/^[|>][+\-]?\d*\s*(?:#.*)?$/';

    /**
     * The values `CC_CATALOGUE_WRITE` may hold to mean "on". Anything else,
     * empty and unset included, means off: this is the one gate that has to
     * fail closed on a value nobody understood, so it does not guess.
     *
     * @var list<string>
     */
    private const array OPT_IN_ON = ['1', 'true', 'yes', 'on'];

    public function __construct(
        #[Autowire('%kernel.project_dir%/translations')]
        private readonly string $translationsDir,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        /**
         * The developer's explicit opt-in, independent of the environment.
         * Set in their own gitignored local override; empty in the
         * committed `web/.env`, so it is off everywhere else by default.
         */
        #[Autowire('%env(CC_CATALOGUE_WRITE)%')]
        private readonly string $catalogueWriteOptIn = '',
    ) {
    }

    /**
     * Whether {@see write()} can do anything at all here: both gates open.
     *
     * Callers use this to decide whether the direct-write path exists
     * before offering it (the dev-submit branch, the draft-all endpoint),
     * so the buttons and the write can never disagree about what is
     * permitted (translations.md §7.1).
     */
    public function isEnabled(): bool
    {
        return 'dev' === $this->environment && $this->isOptedIn();
    }

    private function isOptedIn(): bool
    {
        return \in_array(strtolower(trim($this->catalogueWriteOptIn)), self::OPT_IN_ON, true);
    }

    /**
     * `$allowEnglish` widens the locale guard from the four rider locales to
     * {@see TranslationLimits::OVERLAY_LOCALES} (English included), for the
     * one caller that means to write English on purpose: the dev submit
     * path (translations.md §7.3). Every other caller leaves it false, so
     * the ordinary four-locale guard is exactly what it has always been for
     * the DeepL draft-all endpoint and any future caller that does not ask
     * for English explicitly.
     *
     * @throws NotDevEnvironmentException          kernel environment is not "dev" (translations.md §7.1)
     * @throws CatalogueWriteNotOptedInException   CC_CATALOGUE_WRITE is not explicitly on (translations.md §7.1)
     * @throws CatalogueProtectedKeyException      the key is a consent contract (translations.md §4)
     * @throws InvalidLocaleException              locale is outside what this call may write (translations.md §7.1, §7.3)
     * @throws CatalogueKeyNotFoundException       the key holds no scalar value in that file
     * @throws CatalogueBlockScalarException       the key resolves to a block scalar (`key: |` or `key: >`)
     * @throws CatalogueWriteVerificationException the self-check found the edit touching more than the one key
     */
    public function write(string $locale, string $messageKey, string $value, bool $allowEnglish = false): void
    {
        if ('dev' !== $this->environment) {
            throw new NotDevEnvironmentException(sprintf('Refusing to write "%s": kernel environment is "%s", not "dev" (translations.md §7.1).', $messageKey, $this->environment));
        }

        if (!$this->isOptedIn()) {
            throw new CatalogueWriteNotOptedInException(sprintf('Refusing to write "%s": CC_CATALOGUE_WRITE is not set to 1 (translations.md §7.1). The kernel environment being "dev" is not on its own enough to write into a catalogue file; set CC_CATALOGUE_WRITE=1 in your own gitignored local environment override, then restart the app so the container picks it up.', $messageKey));
        }

        // The consent contracts are hashed into the consent ledger under a
        // VERSION and change by a version bump in code, nowhere else
        // (translations.md §4). Enforced here rather than at each caller so
        // the hand-typed dev submit and the draft-all-four route are both
        // covered by one check, the way ProposalService::submit() covers
        // the rider path.
        if (ProtectedKeys::isProtected($messageKey)) {
            throw new CatalogueProtectedKeyException(sprintf('Refusing to write "%s": it is a consent contract, whose wording changes by a VERSION bump in code and nowhere else (translations.md §4).', $messageKey));
        }

        $localeAllowed = $allowEnglish
            ? TranslationLimits::isOverlayLocale($locale)
            : TranslationLimits::isTranslatableLocale($locale);
        if (!$localeAllowed) {
            throw new InvalidLocaleException(sprintf(
                'Refusing to write "%s": locale "%s" is not %s (translations.md §7.1).',
                $messageKey,
                $locale,
                $allowEnglish ? 'one of the five catalogues this call may write' : 'one of the four rider locales',
            ));
        }

        $path = sprintf('%s/messages.%s.yaml', $this->translationsDir, $locale);
        $original = @file_get_contents($path);
        if (false === $original) {
            throw new CatalogueKeyNotFoundException(sprintf('Refusing to write "%s": catalogue file "%s" could not be read.', $messageKey, $path));
        }

        $found = $this->locate($original, $messageKey, $path);

        $lines = explode("\n", $original);
        $currentValue = $this->decodeScalar($found['tail']);
        if (null !== $currentValue && $currentValue === $value) {
            // Idempotent: the key already holds this value, byte for byte.
            // Leave the file untouched rather than rewrite an identical line.
            return;
        }

        $lines[$found['lineIndex']] = $found['indent'].$found['key'].": '".str_replace("'", "''", $value)."'";
        $newContent = implode("\n", $lines);

        $this->verify($original, $newContent, $messageKey, $value, $path);

        $this->writeAtomically($path, $newContent);
    }

    /**
     * Writes through a temporary file in the same directory and renames it
     * over the target.
     *
     * `file_put_contents()` truncates first and then writes: interrupt it
     * (a killed dev server, a full disk) and the catalogue is left half
     * written, which is the one failure mode this class exists to prevent
     * and the only one its self-check cannot see, because the self-check
     * runs before the bytes leave memory. `rename()` within one filesystem
     * is atomic, so a reader sees either the whole old file or the whole
     * new one and never a truncated one.
     *
     * @throws CatalogueWriteVerificationException the temporary file could not be written or moved into place
     */
    private function writeAtomically(string $path, string $newContent): void
    {
        $tmp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (false === @file_put_contents($tmp, $newContent)) {
            @unlink($tmp);

            throw new CatalogueWriteVerificationException(sprintf('Refusing to write "%s": its temporary file could not be written (translations.md §7.4).', $path));
        }

        // Match the catalogue's own mode rather than inherit the process
        // umask: a file written 600 is unreadable to the container's
        // php-fpm user on the next request (see the dev-stack note in
        // docs/specs/dev-environment.md).
        @chmod($tmp, 0644);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            throw new CatalogueWriteVerificationException(sprintf('Refusing to write "%s": its temporary file could not be moved into place (translations.md §7.4).', $path));
        }
    }

    /**
     * Walks the file's indentation to find the single line holding
     * `$messageKey`'s scalar value.
     *
     * @return array{lineIndex: int, indent: string, key: string, tail: string}
     */
    private function locate(string $content, string $messageKey, string $path): array
    {
        $lines = explode("\n", $content);
        $lineCount = \count($lines);

        /** @var list<array{indent: int, key: string}> $stack */
        $stack = [];

        for ($lineIndex = 0; $lineIndex < $lineCount; ++$lineIndex) {
            $line = $lines[$lineIndex];
            $trimmed = ltrim($line, ' ');
            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                // Blank line or a whole-line comment: neither is part of the
                // key structure, so it neither opens nor closes a level.
                continue;
            }

            if (!preg_match(self::KEY_LINE_PATTERN, $line, $m)) {
                // Not a structural "key:" line: either a block scalar's
                // literal content, or something this walker does not need
                // to understand because it can never be $messageKey's line.
                continue;
            }

            $indentLen = \strlen($m['indent']);
            while ([] !== $stack && end($stack)['indent'] >= $indentLen) {
                array_pop($stack);
            }

            $segments = array_column($stack, 'key');
            $segments[] = $m['key'];
            $currentPath = implode('.', $segments);
            $tail = trim($m['tail']);

            if ('' === $tail) {
                // A mapping: $m['key'] has children on the following, more
                // indented lines. Keep it on the stack as their parent.
                $stack[] = ['indent' => $indentLen, 'key' => $m['key']];
                if ($currentPath === $messageKey) {
                    // The requested key names a section, not a value.
                    throw new CatalogueKeyNotFoundException(sprintf('Refusing to write "%s": that key is a section in "%s", not a value.', $messageKey, $path));
                }

                continue;
            }

            // A leaf: it has no children, so it never joins the stack.
            if (preg_match(self::BLOCK_SCALAR_PATTERN, $tail)) {
                if ($currentPath === $messageKey) {
                    throw new CatalogueBlockScalarException(sprintf('Refusing to write "%s": it is a block scalar (`%s: %s`) in "%s", not a single-line value.', $messageKey, $m['key'], $tail, $path));
                }

                // Skip the block scalar's own content: those lines are more
                // indented than this key (or blank), and are literal text,
                // not YAML keys. Advance the index past them by indentation
                // rather than trust KEY_LINE_PATTERN not to match one by
                // coincidence.
                while ($lineIndex + 1 < $lineCount) {
                    $candidate = $lines[$lineIndex + 1];
                    $candidateTrimmed = ltrim($candidate, ' ');
                    if ('' === $candidateTrimmed) {
                        ++$lineIndex;
                        continue;
                    }
                    $candidateIndent = \strlen($candidate) - \strlen($candidateTrimmed);
                    if ($candidateIndent > $indentLen) {
                        ++$lineIndex;
                        continue;
                    }
                    break;
                }
                continue;
            }

            if ($currentPath === $messageKey) {
                return ['lineIndex' => $lineIndex, 'indent' => $m['indent'], 'key' => $m['key'], 'tail' => $tail];
            }
        }

        throw new CatalogueKeyNotFoundException(sprintf('Refusing to write "%s": that key does not exist in "%s".', $messageKey, $path));
    }

    /**
     * Decodes a quoted scalar's tail back to the raw string it holds, for
     * the idempotency check. Returns null when the tail is not a
     * recognisable quoted scalar (unexpected, but then the caller simply
     * proceeds to write and lets {@see verify()} confirm correctness).
     */
    private function decodeScalar(string $tail): ?string
    {
        if (preg_match("/^'((?:[^']|'')*)'$/", $tail, $m)) {
            return str_replace("''", "'", $m[1]);
        }

        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $tail, $m)) {
            $decoded = preg_replace_callback('/\\\\(.)/', static fn (array $m2): string => match ($m2[1]) {
                'n' => "\n",
                't' => "\t",
                'r' => "\r",
                '"' => '"',
                '\\' => '\\',
                default => $m2[1],
            }, $m[1]);

            return $decoded ?? $m[1];
        }

        return null;
    }

    /**
     * Parses the original and candidate content and requires them to
     * differ at exactly the one key being written, with the new value
     * equal to what was asked for. Runs entirely in memory before the
     * file is touched, so a failure here leaves nothing on disk.
     *
     * @throws CatalogueWriteVerificationException
     */
    private function verify(string $original, string $newContent, string $messageKey, string $value, string $path): void
    {
        $before = self::flatten(Yaml::parse($original));

        try {
            $after = self::flatten(Yaml::parse($newContent));
        } catch (\Throwable $e) {
            // A value that embeds a raw newline, for example, turns the
            // single quoted scalar into two physical lines: still inside the
            // same quotes, but no longer the file this class promises to
            // produce. Symfony's parser rejects that outright rather than
            // silently folding it, so surface that as the same refusal
            // instead of an uncaught parser exception.
            throw new CatalogueWriteVerificationException(sprintf('Refusing to write "%s": the edit would make "%s" invalid YAML (translations.md §7.4): %s', $messageKey, $path, $e->getMessage()), previous: $e);
        }

        if (array_keys($before) !== array_keys($after)) {
            throw new CatalogueWriteVerificationException(sprintf('Refusing to write "%s": the edit would change the key set of "%s" (translations.md §7.4).', $messageKey, $path));
        }

        $changed = [];
        foreach ($before as $key => $beforeValue) {
            if ($beforeValue !== $after[$key]) {
                $changed[] = $key;
            }
        }

        if ([$messageKey] !== $changed) {
            throw new CatalogueWriteVerificationException(sprintf('Refusing to write "%s": the edit would also change %s in "%s" (translations.md §7.4).', $messageKey, [] === $changed ? 'nothing (the parsed value did not move)' : implode(', ', $changed), $path));
        }

        if (($after[$messageKey] ?? null) !== $value) {
            throw new CatalogueWriteVerificationException(sprintf('Refusing to write "%s": the written line does not read back as the value given (translations.md §7.4).', $messageKey));
        }
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $flatKey = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            if (\is_array($value)) {
                $out += self::flatten($value, $flatKey);
            } else {
                $out[$flatKey] = $value;
            }
        }

        return $out;
    }
}
