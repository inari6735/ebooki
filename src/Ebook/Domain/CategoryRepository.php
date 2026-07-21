<?php declare(strict_types=1);

namespace App\Ebook\Domain;

interface CategoryRepository
{
    /** @return list<Category> ordered for display */
    public function all(): array;

    public function findBySlug(string $slug): ?Category;
}
