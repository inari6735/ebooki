<?php declare(strict_types=1);

namespace App\Commerce\Presentation\Admin;

use App\Commerce\Application\RefundOrder;
use App\Commerce\Infrastructure\Backoffice\PaymentsReadModel;
use App\Commerce\Domain\Payment\Exception\PaymentRefundFailed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Back-office payment analysis: the payments list, a single order's full audit
 * (event timeline + provider notifications + double-entry ledger), and the refund
 * action. Admin-only.
 */
#[IsGranted('ROLE_ADMIN')]
final class AdminPaymentsController extends AbstractController
{
    public function __construct(private readonly PaymentsReadModel $payments)
    {
    }

    #[Route('/admin', name: 'app_admin', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_admin_payments');
    }

    #[Route('/admin/platnosci', name: 'app_admin_payments', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $status = $request->query->get('status');

        return $this->render('commerce/admin/payments.html.twig', [
            'orders' => $this->payments->list($status),
            'status' => $status,
        ]);
    }

    #[Route('/admin/platnosci/{orderId}', name: 'app_admin_payment_detail', methods: ['GET'])]
    public function detail(string $orderId): Response
    {
        $summary = $this->payments->summary($orderId);
        if (null === $summary) {
            throw $this->createNotFoundException();
        }

        $ledger = $this->payments->ledger($orderId);
        $debits = array_sum(array_map(static fn (array $e): int => 'DR' === $e['direction'] ? (int) $e['amount'] : 0, $ledger));
        $credits = array_sum(array_map(static fn (array $e): int => 'CR' === $e['direction'] ? (int) $e['amount'] : 0, $ledger));

        return $this->render('commerce/admin/payment_detail.html.twig', [
            'order' => $summary,
            'timeline' => $this->payments->timeline($orderId),
            'notifications' => $this->payments->notifications($orderId),
            'ledger' => $ledger,
            'ledgerBalanced' => $debits === $credits,
            'refundable' => \in_array($summary['status'], ['paid', 'fulfilled'], true),
        ]);
    }

    #[Route('/admin/platnosci/{orderId}/zwrot', name: 'app_admin_payment_refund', methods: ['POST'])]
    public function refund(string $orderId, Request $request, RefundOrder $refundOrder): Response
    {
        if (!$this->isCsrfTokenValid('admin_refund', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!Uuid::isValid($orderId)) {
            throw $this->createNotFoundException();
        }

        $reason = trim((string) $request->request->get('reason')) ?: 'Zwrot zainicjowany przez administratora.';

        try {
            $refundOrder(Uuid::fromString($orderId), $reason);
            $this->addFlash('success', 'Zwrot został zainicjowany.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (PaymentRefundFailed) {
            $this->addFlash('error', 'Provider odrzucił zwrot. Spróbuj ponownie później.');
        }

        return $this->redirectToRoute('app_admin_payment_detail', ['orderId' => $orderId]);
    }
}
