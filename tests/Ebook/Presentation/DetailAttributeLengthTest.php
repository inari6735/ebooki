<?php declare(strict_types=1);

namespace App\Tests\Ebook\Presentation;

use App\Ebook\Domain\Category;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * "Szczegółowe informacje" rows have per-field length caps enforced on the backend
 * (the frontend applies the same via maxlength): an over-long key or value keeps
 * the author on step 2 with an error; rows at the limit advance normally.
 */
final class DetailAttributeLengthTest extends WebTestCase
{
    public function testOverLongKeyKeepsAuthorOnStep2(): void
    {
        $client = self::createClient();
        $this->seedCategory();

        $this->submitDetails($client, str_repeat('a', PublishEbookData::DETAIL_KEY_MAX + 1), 'Bookly');

        self::assertResponseStatusCodeSame(422); // re-rendered step 2 (Turbo-friendly)
        self::assertSelectorTextContains('body', 'Szczegółowe informacje: nazwa może mieć maksymalnie');
    }

    public function testOverLongValueKeepsAuthorOnStep2(): void
    {
        $client = self::createClient();
        $this->seedCategory();

        $this->submitDetails($client, 'Wydawca', str_repeat('b', PublishEbookData::DETAIL_VALUE_MAX + 1));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Szczegółowe informacje: nazwa może mieć maksymalnie');
    }

    public function testRowsAtTheLimitAdvanceToStep3(): void
    {
        $client = self::createClient();
        $this->seedCategory();

        $this->submitDetails($client, str_repeat('a', PublishEbookData::DETAIL_KEY_MAX), str_repeat('b', PublishEbookData::DETAIL_VALUE_MAX));

        self::assertResponseRedirects();
        self::assertStringEndsWith('/wystaw-ebook/3', (string) $client->getResponse()->headers->get('Location'));
    }

    private function submitDetails(KernelBrowser $client, string $key, string $value): void
    {
        // Prime the stateless CSRF cookie the way a real visitor would.
        $client->request('GET', 'https://localhost/wystaw-ebook/1');
        $client->request('POST', 'https://localhost/wystaw-ebook/2', [
            'ebook_details' => [
                'title' => 'Test', 'author' => 'Jan', 'category' => 'kategoria-testowa', 'language' => 'pl',
                'shortDescription' => 'Krótki', 'description' => 'Opis.', '_token' => 'csrf-token',
            ],
            'detailKeys' => [$key],
            'detailValues' => [$value],
        ]);
    }

    private function seedCategory(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Category(Uuid::v7(), 'Kategoria testowa', 'kategoria-testowa'));
        $em->flush();
    }
}
