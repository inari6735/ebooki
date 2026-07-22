<?php declare(strict_types=1);

namespace App\Commerce\Presentation;

use App\Commerce\Application\ConfirmPaymentFromProvider;
use App\Commerce\Application\FailPaymentFromProvider;
use App\Commerce\Infrastructure\Payment\PayU\PayUGateway;
use App\Commerce\Infrastructure\Payment\PayU\PayUSignatureVerifier;
use App\Commerce\Infrastructure\Payment\PaymentNotificationLog;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * PayU notification webhook — the source of truth for payment. Public (no auth,
 * no CSRF): PayU posts server-to-server. The `OpenPayU-Signature` is verified on
 * the RAW body; only a COMPLETED status triggers confirmation, which is dispatched
 * ASYNCHRONOUSLY. Access is NEVER granted here — only after the async verify.
 *
 * PayU sends several notifications per order (PENDING → COMPLETED); we act on and
 * log only COMPLETED, and rely on the aggregate's idempotency for redeliveries.
 */
final class PayUNotificationController extends AbstractController
{
    public function __construct(
        private readonly PayUSignatureVerifier $verifier,
        private readonly PaymentNotificationLog $notificationLog,
        private readonly CommandBus $commandBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/platnosc/payu/notify', name: 'app_payu_notify', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();
        $data = json_decode($raw, true);
        if (!\is_array($data) || !isset($data['order']) || !\is_array($data['order'])) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $signature = $request->headers->get('OpenPayU-Signature') ?? $request->headers->get('X-OpenPayU-Signature');
        if (!$this->verifier->isValid($raw, $signature)) {
            $this->logger->warning('PayU notification with invalid signature', ['extOrderId' => $data['order']['extOrderId'] ?? null]);

            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $order = $data['order'];
        $extOrderId = (string) ($order['extOrderId'] ?? '');
        $payuOrderId = (string) ($order['orderId'] ?? '');
        $currency = Currency::tryFrom((string) ($order['currencyCode'] ?? ''));
        $amount = (int) ($order['totalAmount'] ?? 0);

        // Act only on TERMINAL statuses: COMPLETED (paid) or CANCELED (failed).
        // Everything else (PENDING, WAITING_FOR_CONFIRMATION, NEW, …) is transient
        // and just acknowledged. Non-usable payloads are acknowledged too.
        $status = $order['status'] ?? null;
        if (!\in_array($status, ['COMPLETED', 'CANCELED'], true) || null === $currency
            || !Uuid::isValid($extOrderId) || '' === $payuOrderId) {
            return new Response('', Response::HTTP_OK);
        }

        $isNew = $this->notificationLog->record(
            provider: PayUGateway::PROVIDER,
            sessionId: $extOrderId,
            providerOrderId: $payuOrderId,
            amount: $amount,
            currency: $currency->value,
            methodId: null,
            signatureValid: true,
            rawPayload: $data,
        );

        if ($isNew) {
            $this->commandBus->dispatch('COMPLETED' === $status
                ? new ConfirmPaymentFromProvider($extOrderId, $payuOrderId, $amount, $currency, null)
                : new FailPaymentFromProvider($extOrderId, 'Płatność nieudana (PayU: ' . $status . ').'));
        }

        return new Response('', Response::HTTP_OK);
    }
}
