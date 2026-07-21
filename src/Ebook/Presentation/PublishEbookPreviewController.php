<?php declare(strict_types=1);

namespace App\Ebook\Presentation;

use App\Ebook\Domain\CategoryRepository;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Live preview of the eBook detail page, rendered from the wizard's session data.
 * Reached from the "Publikacja" step (opens in a new tab) so authors can see how
 * their eBook page will look before publishing. No persistence — reads the same
 * session DTO the wizard fills in.
 */
final class PublishEbookPreviewController extends AbstractController
{
    public function __construct(
        private readonly CategoryRepository $categories,
    ) {
    }

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
            'category' => $this->categoryName($data->category),
            'categorySlug' => $data->category,
            'language' => Languages::exists($data->language) ? Languages::getName($data->language) : $data->language,
            'cover' => null !== $data->coverMediaId
                ? $this->generateUrl('app_ebook_staged_preview', ['mediaId' => $data->coverMediaId])
                : null,
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

    private function categoryName(?string $slug): string
    {
        if (null === $slug) {
            return 'Kategoria';
        }

        return $this->categories->findBySlug($slug)?->getName() ?? $slug;
    }
}
