<?php declare(strict_types=1);

namespace App\Ebook\Domain;

use Symfony\Component\Uid\Uuid;

interface MediaRepository
{
    public function save(Media $media): void;

    public function get(Uuid $id): ?Media;

    public function remove(Media $media): void;

    /** @return list<Media> staged (pending) files first created before $olderThan */
    public function stalePending(\DateTimeImmutable $olderThan): array;
}
