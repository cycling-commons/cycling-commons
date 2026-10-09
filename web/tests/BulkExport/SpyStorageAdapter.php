<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\BulkExport;

use League\Flysystem\Config;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * An in-memory bucket that counts every call made to it and can be told to
 * fail one: the export tests use it to prove that a request touches no
 * storage, and that a build failing halfway leaves nothing behind.
 */
final class SpyStorageAdapter extends InMemoryFilesystemAdapter
{
    /** @var list<string> "operation path", in call order */
    public array $calls = [];

    /** @var (\Closure(string, string): void)|null throws to make a call fail */
    public ?\Closure $failWhen = null;

    #[\Override]
    public function fileExists(string $path): bool
    {
        $this->hit('fileExists', $path);

        return parent::fileExists($path);
    }

    #[\Override]
    public function directoryExists(string $path): bool
    {
        $this->hit('directoryExists', $path);

        return parent::directoryExists($path);
    }

    #[\Override]
    public function write(string $path, string $contents, Config $config): void
    {
        $this->hit('write', $path);
        parent::write($path, $contents, $config);
    }

    #[\Override]
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->hit('writeStream', $path);
        parent::writeStream($path, $contents, $config);
    }

    #[\Override]
    public function read(string $path): string
    {
        $this->hit('read', $path);

        return parent::read($path);
    }

    #[\Override]
    public function readStream(string $path)
    {
        $this->hit('readStream', $path);

        return parent::readStream($path);
    }

    #[\Override]
    public function delete(string $path): void
    {
        $this->hit('delete', $path);
        parent::delete($path);
    }

    #[\Override]
    public function deleteDirectory(string $path): void
    {
        $this->hit('deleteDirectory', $path);
        parent::deleteDirectory($path);
    }

    #[\Override]
    public function listContents(string $path, bool $deep): iterable
    {
        $this->hit('listContents', $path);

        return parent::listContents($path, $deep);
    }

    private function hit(string $operation, string $path): void
    {
        $this->calls[] = $operation.' '.$path;
        if (null !== $this->failWhen) {
            ($this->failWhen)($operation, $path);
        }
    }
}
