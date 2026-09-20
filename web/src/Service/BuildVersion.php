<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Service;

/**
 * Footer build stamp: REVISION, then git, then env, then `dev`.
 *
 * A deployed release has no .git, only REVISION. Its `number` is then the
 * VERSION file the deploy wrote beside it, else `APP_BUILD_VERSION`, else the
 * first twelve characters of the commit; `commit` is always REVISION's sha, so
 * the label can never move the source link off the running build.
 *
 * `commit` is the bare object name, empty when the running code cannot name
 * one, and `url` is the offer built from it: the repository root with
 * `/commit/<sha>` appended, or the bare root when there is no commit. AGPL
 * section 13 obliges us to offer THIS build's Corresponding Source, and only a
 * commit link says which build that is; `number` may be a `git describe`
 * string, which no forge resolves.
 *
 * The url is derived HERE rather than by each caller, because the footer and
 * `/humans.txt` must not be able to disagree about which code is running.
 *
 * @api
 */
final class BuildVersion
{
    /** @var array{number: string, date: string, commit: string, url: string}|null */
    private static ?array $cached = null;

    /** @var callable(string): ?string */
    private $run;

    /**
     * @param callable(string): ?string|null $run test seam: command -> stdout (null = failed)
     */
    public function __construct(
        private readonly string $projectDir,
        // ?string: env(default::VAR) is NULL when unset; the footer must not 500.
        private readonly ?string $envFallback = '',
        ?callable $run = null,
        private readonly string $repoUrl = '',
    ) {
        $this->run = $run ?? static function (string $cmd): ?string {
            // disable_functions lists shell_exec as undefined; calling it 500s the footer.
            if (!\function_exists('shell_exec')) {
                return null;
            }

            /** @psalm-suppress ForbiddenCode */
            $out = @shell_exec($cmd.' 2>/dev/null');

            return \is_string($out) && '' !== trim($out) ? trim($out) : null;
        };
    }

    /** @return array{number: string, date: string, commit: string, url: string} */
    public function stamp(): array
    {
        if (null === self::$cached) {
            $found = $this->derive();
            $root = rtrim($this->repoUrl, '/');
            self::$cached = $found + [
                'url' => '' !== $found['commit'] ? $root.'/commit/'.$found['commit'] : $root,
            ];
        }

        return self::$cached;
    }

    /** Test seam: per-worker cache must not leak between tests. */
    public static function reset(): void
    {
        self::$cached = null;
    }

    /** @return array{number: string, date: string, commit: string} */
    private function derive(): array
    {
        foreach ([$this->projectDir, \dirname($this->projectDir)] as $root) {
            $revision = $root.'/REVISION';
            if (!is_file($revision)) {
                continue;
            }
            $sha = @file_get_contents($revision);
            if (!\is_string($sha) || '' === trim($sha)) {
                continue;
            }
            $mtime = @filemtime($revision);

            // The release name, when the deploy recorded one beside the commit
            // (VERSION, the `git describe` output taken before .git is stripped),
            // else the label the environment carries, else the commit itself.
            $version = @file_get_contents($root.'/VERSION');
            $number = \is_string($version) && '' !== trim($version)
                ? trim($version)
                : (null !== $this->envFallback && '' !== $this->envFallback ? $this->envFallback : substr(trim($sha), 0, 12));

            return [
                'number' => $number,
                'date' => false !== $mtime ? date('Y-m-d', $mtime) : '',
                'commit' => trim($sha),
            ];
        }

        // Working copy: git in web/ or the repo root above it.
        foreach ([$this->projectDir, \dirname($this->projectDir)] as $root) {
            $git = 'git -C '.escapeshellarg($root);
            $number = ($this->run)($git." describe --tags --match 'v*' --always");
            if (null === $number) {
                continue;
            }
            $date = ($this->run)($git.' log -1 --format=%cs') ?? '';

            return ['number' => $number, 'date' => $date, 'commit' => ($this->run)($git.' rev-parse HEAD') ?? ''];
        }

        if (null !== $this->envFallback && '' !== $this->envFallback) {
            return ['number' => $this->envFallback, 'date' => '', 'commit' => ''];
        }

        return ['number' => 'dev', 'date' => '', 'commit' => ''];
    }
}
