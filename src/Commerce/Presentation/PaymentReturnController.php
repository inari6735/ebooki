<?php declare(strict_types=1);

namespace App\Commerce\Presentation;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Where Przelewy24 sends the buyer's browser back after paying. UX ONLY — it must
 * never grant access or confirm payment (that is the webhook's job). It just shows
 * the current order status from the read model; if the async confirmation has not
 * landed yet, it shows a "processing" state.
 */
final class PaymentReturnController extends AbstractController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/platnosc/powrot/{orderId}', name: 'app_checkout_return', methods: ['GET'])]
    public function __invoke(string $orderId): Response
    {
        $row = $this->connection->fetchAssociative(
            'SELECT title, status, ebook_id FROM commerce_orders WHERE id = ?',
            [$orderId],
        );

        if (false === $row) {
            throw $this->createNotFoundException();
        }

        $fulfilled = 'fulfilled' === $row['status'];

        return $this->render('commerce/return.html.twig', [
            'title' => $row['title'],
            'status' => $row['status'],
            'paid' => 'paid' === $row['status'] || $fulfilled,
            'fulfilled' => $fulfilled,
            'ebookId' => $row['ebook_id'],
            'failed' => 'failed' === $row['status'],
        ]);
    }
}
