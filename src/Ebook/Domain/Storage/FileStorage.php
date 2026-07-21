<?php declare(strict_types=1);

namespace App\Ebook\Domain\Storage;

/**
 * The application's port to file storage. It is intentionally tiny and framework-
 * agnostic so the underlying provider (local disk in dev, S3 in prod, …) can be
 * swapped by changing the infrastructure adapter / Flysystem config alone.
 * Paths are storage keys relative to the configured storage root.
 */
interface FileStorage
{
    /** @param resource $contents */
    public function writeStream(string $path, $contents): void;

    public function move(string $source, string $destination): void;

    public function delete(string $path): void;

    /** @return resource */
    public function readStream(string $path);

    public function fileExists(string $path): bool;

    public function fileSize(string $path): int;

    public function deleteDirectory(string $path): void;

    /** @return list<string> paths of the immediate sub-directories of $path */
    public function directories(string $path): array;
}
