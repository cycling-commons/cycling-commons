<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

/**
 * The build stamp in every footer, derived from the release tag.
 *
 * It was a hand-edited constant in version.js ('Demo v0.1.2 · 2026-06-26') —
 * which went stale the way every hand-edited date does, and said "demo" long
 * after the site stopped being one. Deploys here are server-side pulls of the
 * full repository (no CI step touches served files), so the repo itself is
 * present next to the app in production and can simply be asked:
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
        // web/ first, then the repo root above it — prod pulls the whole repo,
        // so .git sits one level up from the kernel's project dir.
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
