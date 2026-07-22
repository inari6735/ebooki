<?php declare(strict_types=1);

namespace App\Commerce\Presentation\Admin;

use App\Commerce\Infrastructure\Backoffice\ReconciliationReadModel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reconciliation dashboard: local anomalies that warrant a human's attention.
 */
#[IsGranted('ROLE_ADMIN')]
final class AdminReconciliationController extends AbstractController
{
    public function __construct(private readonly ReconciliationReadModel $reconciliation)
    {
    }

    #[Route('/panel/reconciliation', name: 'app_admin_reconciliation', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('commerce/admin/reconciliation.html.twig', [
            'active' => 'reconciliation',
            'stuck' => $this->reconciliation->stuckAwaitingPayment(new \DateTimeImmutable('-1 hour')),
            'paidNotFulfilled' => $this->reconciliation->paidNotFulfilled(),
            'unbalanced' => $this->reconciliation->unbalancedLedger(),
            'invalidSignatures' => $this->reconciliation->invalidSignatureNotifications(),
        ]);
    }
}
