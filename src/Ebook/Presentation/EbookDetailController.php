<?php declare(strict_types=1);

namespace App\Ebook\Presentation;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * eBook detail (product) page. Front-end only for now — there is no catalog
 * backend yet, so the page renders a fixed sample eBook. When the Course/catalog
 * context lands, look the eBook up by {slug} and drop the mock below.
 */
final class EbookDetailController extends AbstractController
{
    #[Route('/ebook/{slug}', name: 'app_ebook_show', defaults: ['slug' => 'swiatlo-na-koncu-drogi'], methods: ['GET'])]
    public function __invoke(string $slug): Response
    {
        // Mock eBook — mirrors the design until the catalog backend exists.
        $ebook = [
            'slug' => $slug,
            'title' => 'Światło na końcu drogi',
            'author' => 'Anna Kowalska',
            'category' => 'Rozwój osobisty',
            'categorySlug' => 'rozwoj-osobisty',
            'language' => 'Polski',
            'format' => 'PDF',
            'cover' => null, // no cover store yet → gradient placeholder
            'shortDescription' => 'Praktyczny przewodnik po odzyskiwaniu nadziei i kierunku w trudnych chwilach.',
            'rating' => 4.8,
            'ratingCount' => 326,
            'ratingNote' => 'Zaufany wybór tysięcy czytelników',
            'price' => 29.99,
            'details' => [
                ['key' => 'Liczba stron', 'value' => '248'],
                ['key' => 'Rok wydania', 'value' => '2024'],
                ['key' => 'Wydawca', 'value' => 'Bookly'],
                ['key' => 'Format pliku', 'value' => 'PDF, EPUB'],
                ['key' => 'ISBN', 'value' => '978-83-000000-0-0'],
            ],
            'description' => [
                'Znajdziesz tu praktyczne ćwiczenia, prawdziwe historie i narzędzia, które pomogą Ci odnaleźć światło — nawet wtedy, gdy droga wydaje się ciemna.',
                'To książka o odwadze, zmianie i wierze w siebie. Dla każdego, kto czuje, że utknął, ale wie, że zasługuje na więcej.',
            ],
        ];

        return $this->render('ebook/show.html.twig', ['ebook' => $ebook]);
    }
}
