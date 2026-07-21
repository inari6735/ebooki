<?php declare(strict_types=1);

namespace App\Ebook\Infrastructure\Storage;

use App\Ebook\Domain\Storage\FileStorage;
use League\Flysystem\FilesystemOperator;

/**
 * Flysystem-backed {@see FileStorage}. Bound to the `ebook.storage` filesystem
 * (see config/packages/flysystem.yaml) via the `$ebookStorage` autowiring alias
 * the bundle generates. The provider (local/S3/…) is a config concern here.
 */
final readonly class FlysystemFileStorage implements FileStorage
{
    public function __construct(private FilesystemOperator $ebookStorage)
    {
    }

    public function writeStream(string $path, $contents): void
    {
        $this->ebookStorage->writeStream($path, $contents);
    }

    public function move(string $source, string $destination): void
    {
        $this->ebookStorage->move($source, $destination);
    }

    public function delete(string $path): void
    {
        $this->ebookStorage->delete($path);
    }

    public function readStream(string $path)
    {
        return $this->ebookStorage->readStream($path);
    }

    public function fileExists(string $path): bool
    {
        return $this->ebookStorage->fileExists($path);
    }

    public function fileSize(string $path): int
    {
        return $this->ebookStorage->fileSize($path);
    }
}
