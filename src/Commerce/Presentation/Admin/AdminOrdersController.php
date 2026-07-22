<?php declare(strict_types=1);

namespace App\Commerce\Presentation\Admin;

use App\Commerce\Application\RefundOrder;
use App\Commerce\Domain\Payment\Exception\PaymentRefundFailed;
use App\Commerce\Infrastructure\Backoffice\PaymentsReadModel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Admin orders back-office, embedded in the account panel: all orders (one row
 * each), and a single order's full audit — its event history in sequence, the
 * payment progression (payment events interleaved with provider notifications),
 * the double-entry ledger, and the refund action.
 */
#[IsGranted('ROLE_ADMIN')]
final class AdminOrdersController extends AbstractController
{
    private const array PAYMENT_EVENTS = ['PaymentInitiated', 'PaymentConfirmed', 'PaymentFailed', 'OrderRefunded'];

    public function __construct(private readonly PaymentsReadModel $payments)
    {
    }

    #[Route('/admin', name: 'app_admin', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_admin_orders');
    }

    #[Route('/panel/zamowienia', name: 'app_admin_orders', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $status = $request->query->get('status');

        return $this->render('commerce/admin/orders.html.twig', [
            'active' => 'zamowienia',
            'orders' => $this->payments->list($status),
            'status' => $status,
        ]);
    }

    #[Route('/panel/zamowienia/{orderId}', name: 'app_admin_order_detail', methods: ['GET'])]
    public function detail(string $orderId): Response
    {
        $summary = $this->payments->summary($orderId);
        if (null === $summary) {
            throw $this->createNotFoundException();
        }

        $timeline = $this->payments->timeline($orderId);
        $ledger = $this->payments->ledger($orderId);
        $debits = array_sum(array_map(static fn (array $e): int => 'DR' === $e['direction'] ? (int) $e['amount'] : 0, $ledger));
        $credits = array_sum(array_map(static fn (array $e): int => 'CR' === $e['direction'] ? (int) $e['amount'] : 0, $ledger));

        return $this->render('commerce/admin/order_detail.html.twig', [
            'active' => 'zamowienia',
            'order' => $summary,
            'timeline' => $timeline,
            'journey' => $this->paymentJourney($timeline, $this->payments->notifications($orderId)),
            'ledger' => $ledger,
            'ledgerBalanced' => $debits === $credits,
            'refundable' => \in_array($summary['status'], ['paid', 'fulfilled'], true),
        ]);
    }

    #[Route('/panel/zamowienia/{orderId}/zwrot', name: 'app_admin_order_refund', methods: ['POST'])]
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

        return $this->redirectToRoute('app_admin_order_detail', ['orderId' => $orderId]);
    }

    /**
     * The payment story in order: payment-related domain events interleaved with
     * the raw provider notifications, sorted chronologically.
     *
     * @param list<array<string, mixed>> $timeline
     * @param list<array<string, mixed>> $notifications
     *
     * @return list<array<string, mixed>>
     */
    private function paymentJourney(array $timeline, array $notifications): array
    {
        $journey = [];
        foreach ($timeline as $e) {
            if (\in_array($e['event'], self::PAYMENT_EVENTS, true)) {
                $journey[] = ['kind' => 'event', 'at' => (string) $e['occurred_at'], 'event' => $e['event'], 'payload' => $e['payload']];
            }
        }
        foreach ($notifications as $n) {
            $journey[] = ['kind' => 'notification', 'at' => (string) $n['received_at'], 'notification' => $n];
        }
        usort($journey, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $journey;
    }
}
