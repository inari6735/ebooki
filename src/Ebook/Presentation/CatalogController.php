<?php declare(strict_types=1);

namespace App\Ebook\Presentation;

use App\Ebook\Domain\CategoryRepository;
use App\Ebook\Infrastructure\EbookCatalog;
use App\Ebook\Infrastructure\MediaThumbnailRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Public eBook catalogue — browse all published eBooks, filter by category and
 * sort. Only PUBLISHED eBooks are listed (consistent with the detail page).
 */
final class CatalogController extends AbstractController
{
    private const int PER_PAGE = 12;

    private const array SORT_LABELS = [
        'newest' => 'Najnowsze',
        'price_asc' => 'Cena: rosnąco',
        'price_desc' => 'Cena: malejąco',
        'title' => 'Alfabetycznie',
    ];

    public function __construct(
        private readonly EbookCatalog $catalog,
        private readonly CategoryRepository $categories,
        private readonly MediaThumbnailRepository $thumbnails,
    ) {
    }

    #[Route('/ebooki', name: 'app_ebooks', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $category = trim((string) $request->query->get('kategoria')) ?: null;
        $sort = (string) $request->query->get('sort', 'newest');
        if (!\in_array($sort, $this->catalog->sortKeys(), true)) {
            $sort = 'newest';
        }

        $total = $this->catalog->count($category);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $request->query->getInt('strona', 1)), $pages);

        $items = $this->catalog->page($category, $sort, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return $this->render('ebook/catalog.html.twig', [
            'ebooks' => $this->withThumbnails(array_map($this->toCard(...), $items)),
            'categories' => array_map(
                static fn ($c): array => ['name' => $c->getName(), 'slug' => $c->getSlug()],
                $this->categories->all(),
            ),
            'activeCategory' => $category,
            'activeCategoryName' => null !== $category ? $this->categories->findBySlug($category)?->getName() : null,
            'sort' => $sort,
            'sortLabels' => self::SORT_LABELS,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function toCard(array $r): array
    {
        return [
            'id' => $r['id'],
            'slug' => $r['slug'],
            'title' => $r['title'],
            'author' => $r['author_name'],
            'coverMediaId' => $r['cover_media_id'],
            'hasCover' => null !== $r['cover_media_id'],
            'category' => $r['category_name'],
            'categorySlug' => $r['category_slug'],
            'shortDescription' => $r['short_description'],
            'isFree' => (bool) (int) $r['is_free'],
            'payWhatYouWant' => (bool) (int) $r['pay_what_you_want'],
            'price' => null !== $r['price_amount'] ? (int) $r['price_amount'] / 100 : null,
            'promoPrice' => null !== $r['promo_price_amount'] ? (int) $r['promo_price_amount'] / 100 : null,
            'currency' => $r['currency'],
        ];
    }

    /**
     * Attaches the list of available thumbnail widths to each card (one batched
     * query for the whole page). The tile builds its srcset from these — cards
     * whose cover has no generated thumbnails get an empty list (→ placeholder).
     *
     * @param list<array<string, mixed>> $cards
     *
     * @return list<array<string, mixed>>
     */
    private function withThumbnails(array $cards): array
    {
        $coverIds = [];
        foreach ($cards as $card) {
            if (null !== $card['coverMediaId']) {
                $coverIds[] = Uuid::fromString($card['coverMediaId']);
            }
        }
        $widths = $this->thumbnails->widthsForMediaIds($coverIds);

        foreach ($cards as &$card) {
            $card['thumbnails'] = null !== $card['coverMediaId'] ? ($widths[$card['coverMediaId']] ?? []) : [];
        }

        return $cards;
    }
}
