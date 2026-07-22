<?php declare(strict_types=1);

namespace App\Commerce\Presentation;

use App\Commerce\Infrastructure\BuyerPurchases;
use App\Ebook\Infrastructure\MediaThumbnailRepository;
use App\User\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * The buyer's "Zakupione" (purchases) panel page — their completed orders with a
 * download for the fulfilled ones.
 */
#[IsGranted('ROLE_USER')]
final class PurchasesController extends AbstractController
{
    public function __construct(
        private readonly BuyerPurchases $purchases,
        private readonly MediaThumbnailRepository $thumbnails,
    ) {
    }

    #[Route('/panel/zakupione', name: 'app_dashboard_purchased', methods: ['GET'])]
    public function __invoke(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $purchases = $this->purchases->forBuyer($user->getId());

        $coverIds = [];
        foreach ($purchases as $p) {
            if (null !== $p['cover_media_id']) {
                $coverIds[] = Uuid::fromString($p['cover_media_id']);
            }
        }

        return $this->render('dashboard/purchased.html.twig', [
            'active' => 'zakupione',
            'purchases' => $purchases,
            'coverThumbs' => $this->thumbnails->widthsForMediaIds($coverIds),
        ]);
    }
}
