<?php declare(strict_types=1);

namespace App\Ebook\Presentation;

use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\EbookStatus;
use App\User\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public eBook detail (product) page. Only PUBLISHED eBooks are reachable under
 * their link — anything else (draft, hidden, unknown slug) is a 404; an author may
 * still preview their own unpublished eBook.
 */
final class EbookDetailController extends AbstractController
{
    public function __construct(private readonly EbookRepository $ebooks)
    {
    }

    #[Route('/ebook/{slug}', name: 'app_ebook_show', methods: ['GET'])]
    public function __invoke(string $slug): Response
    {
        $ebook = $this->ebooks->findBySlug($slug);
        if (null === $ebook || !$this->isVisible($ebook)) {
            throw $this->createNotFoundException();
        }

        return $this->render('ebook/show.html.twig', ['ebook' => $this->toView($ebook)]);
    }

    /** Published eBooks are public; unpublished ones are visible only to their owner. */
    private function isVisible(Ebook $ebook): bool
    {
        if (EbookStatus::PUBLISHED === $ebook->getStatus()) {
            return true;
        }
        $user = $this->getUser();

        return $user instanceof User && $ebook->getOwnerId()->equals($user->getId());
    }

    /** @return array<string, mixed> */
    private function toView(Ebook $ebook): array
    {
        $pricing = $ebook->pricing();
        $units = $pricing->currency()->minorUnits();
        $language = $ebook->getLanguage();

        return [
            'slug' => $ebook->getSlug(),
            'title' => $ebook->getTitle(),
            'author' => $ebook->getAuthorName(),
            'category' => $ebook->getCategory()?->getName() ?? '—',
            'categorySlug' => $ebook->getCategory()?->getSlug() ?? '',
            'language' => Languages::exists($language) ? Languages::getName($language, 'pl') : $language,
            'format' => implode(', ', array_map(static fn ($f): string => strtoupper($f->value), $ebook->formats())),
            'cover' => null !== $ebook->getCover()
                ? $this->generateUrl('app_ebook_cover', ['id' => $ebook->getId()->toRfc4122()])
                : null,
            'shortDescription' => $ebook->getShortDescription() ?? '',
            'rating' => null, // reviews are Phase 2 — the rating block hides itself when null
            'ratingCount' => 0,
            'ratingNote' => '',
            'price' => null !== ($m = $pricing->baseAmountMinor()) ? $m / $units : null,
            'isFree' => $pricing->isFree(),
            'payWhatYouWant' => $pricing->isPayWhatYouWant(),
            'promoPrice' => null !== ($m = $pricing->promoAmountMinor()) ? $m / $units : null,
            'details' => array_map(static fn ($a): array => ['key' => $a->label, 'value' => $a->value], $ebook->attributes()),
            'description' => array_values(array_filter(array_map('trim', explode("\n", (string) $ebook->getDescription())), static fn (string $p): bool => '' !== $p)),
        ];
    }
}
