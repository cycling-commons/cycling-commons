<?php

// SPDX-License-Identifier: AGPL-3.0-only

declare(strict_types=1);

namespace App\Tests\BulkExport;

use League\Flysystem\Config;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * An in-memory bucket that counts every call made to it, keeps the object
 * metadata each write asked for, and can be told to fail one: the export
 * tests use it to prove that a request touches no storage, that a build
 * failing halfway leaves nothing behind, and that every object carries the
 * headers nginx passes through.
 */
final class SpyStorageAdapter extends InMemoryFilesystemAdapter
{
    /** @var list<string> "operation path", in call order */
    public array $calls = [];

    /** The write options the S3 adapter forwards as object metadata. */
    private const array METADATA = ['ContentType', 'CacheControl', 'ContentDisposition', 'ContentEncoding'];

    /** @var array<string, array<string, mixed>> path => the metadata options of its last write */
    public array $metadata = [];

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
        $this->keep($path, $config);
        parent::write($path, $contents, $config);
    }

    #[\Override]
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->hit('writeStream', $path);
        $this->keep($path, $config);
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

    private function keep(string $path, Config $config): void
    {
        $this->metadata[$path] = [];
        foreach (self::METADATA as $option) {
            $value = $config->get($option);
            if (null !== $value) {
                $this->metadata[$path][$option] = $value;
            }
        }
    }

    private function hit(string $operation, string $path): void
    {
        $this->calls[] = $operation.' '.$path;
        if (null !== $this->failWhen) {
            ($this->failWhen)($operation, $path);
        }
    }
}
