<?php declare(strict_types=1);

namespace App\Ebook\Presentation\Twig;

use App\Ebook\Domain\Category;
use App\Ebook\Domain\CategoryRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the catalog categories to the layout so the header's "Kategorie" menu
 * can list them. Kept as a lazy Twig function (evaluated only where called) rather
 * than a global, so pages that don't render it never hit the database.
 */
final class CategoryNavExtension extends AbstractExtension
{
    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('nav_categories', $this->navCategories(...)),
        ];
    }

    /** @return list<Category> */
    public function navCategories(): array
    {
        return $this->categories->all();
    }
}
