<?php

// SPDX-License-Identifier: LicenseRef-PolyForm-Shield-1.0.0

declare(strict_types=1);

namespace App\Service;

/**
 * Footer build stamp: REVISION, then git, then env, then `dev`.
 *
 * @api
 */
final class BuildVersion
{
    /** @var array{number: string, date: string}|null */
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

    /** @return array{number: string, date: string} */
    public function stamp(): array
    {
        return self::$cached ??= $this->derive();
    }

    /** Test seam: per-worker cache must not leak between tests. */
    public static function reset(): void
    {
        self::$cached = null;
    }

    /** @return array{number: string, date: string} */
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

            return [
                'number' => substr(trim($sha), 0, 12),
                'date' => false !== $mtime ? date('Y-m-d', $mtime) : '',
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

            return ['number' => $number, 'date' => $date];
        }

        if (null !== $this->envFallback && '' !== $this->envFallback) {
            return ['number' => $this->envFallback, 'date' => ''];
        }

        return ['number' => 'dev', 'date' => ''];
    }
}
