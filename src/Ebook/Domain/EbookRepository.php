<?php declare(strict_types=1);

namespace App\Ebook\Domain;

use Symfony\Component\Uid\Uuid;

interface EbookRepository
{
    public function save(Ebook $ebook): void;

    public function get(Uuid $id): ?Ebook;

    public function slugExists(string $slug): bool;
}
