<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use App\Ebook\Domain\Category;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The header's "Kategorie" menu is driven by the catalog: available categories are
 * rendered into the (hover-revealed) dropdown, so adding a category surfaces it.
 */
final class HeaderCategoriesTest extends WebTestCase
{
    public function testHeaderRendersAvailableCategories(): void
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Category(Uuid::v7(), 'Biznes i finanse', 'biznes-i-finanse'));
        $em->flush();

        $client->request('GET', 'https://localhost/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('header', 'Biznes i finanse');
    }
}
