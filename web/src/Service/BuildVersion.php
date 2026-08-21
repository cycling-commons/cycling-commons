<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

/**
 * The build stamp in every footer, derived from the release tag.
 *
 * It was a hand-edited constant in version.js ('Demo v0.1.2 · 2026-06-26') —
 * which went stale the way every hand-edited date does, and said "demo" long
 * after the site stopped being one.
 *
 * Sources, in order:
 *
 *   REVISION — written by the deploy script from `git rev-parse HEAD` before it
 *              strips .git. On a deployed host this is the only thing that names
 *              the running commit, and reading it means never shelling out on a
 *              tier where shell_exec is disabled.
 *   git      — for a working copy that still has a repository:
 *
 *   number — `git describe --tags --match 'v*'`: exactly `v0.2.0` when HEAD is
 *            the release tag, `v0.2.0-14-gabc1234` between releases (honest
 *            about being ahead), the short commit hash before any release tag
 *            exists. `--match 'v*'` because the repo carries non-release
 *            utility tags (backup markers) that must never become the footer.
 *   date   — HEAD's commit date: what is deployed is a commit, and its date is
 *            the honest "as of".
 *
 * Computed once per PHP worker (static cache): workers recycle on deploy, so
 * the stamp follows the code with zero deploy-script steps — the constraint
 * the old comment named. Where git is unreachable (the dev container mounts
 * web/ without the repo root) it falls back to APP_BUILD_VERSION, then 'dev',
 * and never breaks a page over a footer.
 *
 * @api Consumed by VersionExtension (Twig) → window.CC_VERSION → version.js.
 */
final class BuildVersion
{
    /** @var array{number: string, date: string}|null */
    private static ?array $cached = null;

    /** @var callable(string): ?string */
    private $run;

    /**
     * @param callable(string): ?string|null $run test seam: command -> stdout
     *                                            (null = execution failed)
     */
    public function __construct(
        private readonly string $projectDir,
        // ?string: env(default::VAR) resolves to NULL when the var is unset,
        // and a footer fallback must not be able to 500 the container.
        private readonly ?string $envFallback = '',
        ?callable $run = null,
    ) {
        $this->run = $run ?? static function (string $cmd): ?string {
            /* A function listed in disable_functions reports as UNDEFINED, so
               calling it raises Error - which @ cannot suppress and nothing
               here catches. The web tier disables shell_exec deliberately
               (proc_open, popen and friends too), so ask before calling.
               Without this guard the footer took every page down with a 500
               on 2026-08-21, despite this class being written to fall back. */
            if (!\function_exists('shell_exec')) {
                return null;
            }

            /* Psalm flags every shell_exec as unsafe. This one runs only the
               fixed `git log`/`git describe` strings composed in version()
               below - no user input reaches it, and the test seam ($run)
               exists precisely so tests never shell out. */
            /** @psalm-suppress ForbiddenCode */
            $out = @shell_exec($cmd.' 2>/dev/null');

            return \is_string($out) && '' !== trim($out) ? trim($out) : null;
        };
    }

    /** @return array{number: string, date: string} */
    public function stamp(): array
    {
        return self::$cached ??= $this->derive();
    }

    /** Test seam: the per-worker cache must not leak between tests. */
    public static function reset(): void
    {
        self::$cached = null;
    }

    /** @return array{number: string, date: string} */
    private function derive(): array
    {
        // A deployed release has no .git: the deploy script records the commit
        // in REVISION and strips .git immediately after cloning. So on a server
        // that file is the ONLY thing naming what is running, and it is checked
        // first - which also means a hardened host never attempts to shell out.
        //
        // `date` here is the deploy date (the file's mtime), not the commit
        // date the git path returns. Both answer "as of when", and a release
        // that carries only a SHA cannot know the latter.
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

            return [
                'number' => substr(trim($sha), 0, 12),
                'date' => false !== $mtime ? date('Y-m-d', $mtime) : '',
            ];
        }

        // web/ first, then the repo root above it — a plain `git pull` deploy
        // keeps the repository next to the app, so .git may sit one level up.
        foreach ([$this->projectDir, \dirname($this->projectDir)] as $root) {
            $git = 'git -C '.escapeshellarg($root);
            $number = ($this->run)($git." describe --tags --match 'v*' --always");
            if (null === $number) {
                continue;
            }
            $date = ($this->run)($git.' log -1 --format=%cs') ?? '';

            return ['number' => $number, 'date' => $date];
        }

        if (null !== $this->envFallback && '' !== $this->envFallback) {
            return ['number' => $this->envFallback, 'date' => ''];
        }

        return ['number' => 'dev', 'date' => ''];
    }
}
