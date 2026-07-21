<?php declare(strict_types=1);

namespace App\Ebook\Presentation;

use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Live preview of the eBook detail page, rendered from the wizard's session data.
 * Reached from the "Publikacja" step (opens in a new tab) so authors can see how
 * their eBook page will look before publishing. No persistence — reads the same
 * session DTO the wizard fills in.
 */
final class PublishEbookPreviewController extends AbstractController
{
    // Mirror of the DetailsStepType choices — kept here so the slug the wizard
    // stores renders as a human label. Centralise once the catalog backend lands.
    private const array CATEGORY_LABELS = [
        'rozwoj-osobisty' => 'Rozwój osobisty',
        'biznes' => 'Biznes',
        'marketing' => 'Marketing',
        'fantastyka' => 'Fantastyka',
        'literatura' => 'Literatura',
    ];

    private const array LANGUAGE_LABELS = [
        'pl' => 'Polski',
        'en' => 'Angielski',
        'de' => 'Niemiecki',
    ];

    #[Route('/wystaw-ebook/podglad', name: 'app_publish_ebook_preview', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        /** @var PublishEbookData $data */
        $data = $request->getSession()->get(PublishEbookController::SESSION_KEY) ?? new PublishEbookData();

        $paragraphs = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R\s*\R/', (string) $data->description) ?: [],
        )));

        $ebook = [
            'slug' => 'podglad',
            'title' => $data->title ?: 'Tytuł eBooka',
            'author' => $data->author ?: 'Autor',
            'category' => self::CATEGORY_LABELS[$data->category] ?? ($data->category ?: 'Kategoria'),
            'categorySlug' => $data->category,
            'language' => self::LANGUAGE_LABELS[$data->language] ?? $data->language,
            'cover' => $data->coverDataUri,
            'shortDescription' => $data->shortDescription ?: 'Krótki opis eBooka pojawi się tutaj.',
            'rating' => null, // no reviews yet for an unpublished eBook
            'price' => $data->price,
            'isFree' => $data->isFree,
            'payWhatYouWant' => $data->payWhatYouWant,
            'promoPrice' => $data->promoPrice,
            'details' => $data->details,
            'description' => $paragraphs ?: ['Opis eBooka pojawi się tutaj.'],
            'preview' => true,
        ];

        return $this->render('ebook/show.html.twig', ['ebook' => $ebook]);
    }
}
