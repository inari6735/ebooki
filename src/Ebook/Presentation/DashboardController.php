<?php declare(strict_types=1);

namespace App\Ebook\Presentation;

use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\EbookStatus;
use App\User\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The signed-in author's panel: their eBooks (published + drafts) with at-a-glance
 * counts. Sales/earnings/views live in the not-yet-built commerce context, so they
 * are shown as "coming soon" rather than faked.
 */
final class DashboardController extends AbstractController
{
    public function __construct(private readonly EbookRepository $ebooks)
    {
    }

    #[Route('/panel', name: 'app_dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $ebooks = $this->ebooks->findByOwner($user->getId());

        $published = array_filter($ebooks, static fn ($e): bool => EbookStatus::PUBLISHED === $e->getStatus());
        $drafts = array_filter($ebooks, static fn ($e): bool => EbookStatus::DRAFT === $e->getStatus());

        return $this->render('dashboard/index.html.twig', [
            'active' => 'pulpit',
            'ebooks' => $ebooks,
            'totalCount' => \count($ebooks),
            'publishedCount' => \count($published),
            'draftCount' => \count($drafts),
        ]);
    }
}
