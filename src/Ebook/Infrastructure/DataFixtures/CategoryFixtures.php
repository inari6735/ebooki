<?php declare(strict_types=1);

namespace App\Ebook\Infrastructure\DataFixtures;

use App\Ebook\Domain\Category;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Uid\Uuid;

/**
 * Base eBook categories. Load with `php bin/console doctrine:fixtures:load`.
 */
final class CategoryFixtures extends Fixture
{
    /** @var array<string, string> name => slug, in display order */
    private const CATEGORIES = [
        'Rozwój osobisty' => 'rozwoj-osobisty',
        'Biznes i finanse' => 'biznes-i-finanse',
        'Marketing i sprzedaż' => 'marketing-i-sprzedaz',
        'Informatyka i technologia' => 'informatyka-i-technologia',
        'Nauka i edukacja' => 'nauka-i-edukacja',
        'Poradniki' => 'poradniki',
        'Zdrowie i uroda' => 'zdrowie-i-uroda',
        'Kuchnia' => 'kuchnia',
        'Literatura piękna' => 'literatura-piekna',
        'Fantastyka i science fiction' => 'fantastyka-i-science-fiction',
        'Kryminał i thriller' => 'kryminal-i-thriller',
        'Dla dzieci i młodzieży' => 'dla-dzieci-i-mlodziezy',
    ];

    public function load(ObjectManager $manager): void
    {
        // Idempotent: safe to run with --append without duplicating (unique slug).
        $existing = array_map(
            static fn (Category $c): string => $c->getSlug(),
            $manager->getRepository(Category::class)->findAll(),
        );

        $position = 0;
        foreach (self::CATEGORIES as $name => $slug) {
            ++$position;
            if (\in_array($slug, $existing, true)) {
                continue;
            }
            $manager->persist(new Category(Uuid::v7(), $name, $slug, position: $position));
        }

        $manager->flush();
    }
}
