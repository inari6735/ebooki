<?php declare(strict_types=1);

namespace App\Ebook\Infrastructure;

use App\Ebook\Domain\Category;
use App\Ebook\Domain\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineCategoryRepository implements CategoryRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function all(): array
    {
        return array_values(
            $this->entityManager->getRepository(Category::class)->findBy([], ['position' => 'ASC', 'name' => 'ASC']),
        );
    }

    public function findBySlug(string $slug): ?Category
    {
        return $this->entityManager->getRepository(Category::class)->findOneBy(['slug' => $slug]);
    }
}
