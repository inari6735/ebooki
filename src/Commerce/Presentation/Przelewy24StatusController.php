<?php declare(strict_types=1);

namespace App\Commerce\Presentation;

use App\Commerce\Application\ConfirmPaymentFromProvider;
use App\Commerce\Infrastructure\Payment\Przelewy24\P24NotificationVerifier;
use App\Commerce\Infrastructure\Payment\Przelewy24\Przelewy24Gateway;
use App\Commerce\Infrastructure\Payment\PaymentNotificationLog;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Przelewy24 `urlStatus` webhook — the source of truth for payment. Public
 * (no auth, no CSRF): P24 posts server-to-server with no cookies. The signature
 * is verified on the parsed body; the raw notification is logged (and deduped);
 * then heavy confirmation work is dispatched ASYNCHRONOUSLY and 200 is returned
 * fast. Access is NEVER granted here — only after the async verify + confirm.
 */
final class Przelewy24StatusController extends AbstractController
{
    public function __construct(
        private readonly P24NotificationVerifier $verifier,
        private readonly PaymentNotificationLog $notificationLog,
        private readonly CommandBus $commandBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/platnosc/przelewy24/status', name: 'app_p24_status', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->verifier->isValid($data)) {
            $this->logger->warning('P24 notification with invalid signature', ['sessionId' => $data['sessionId'] ?? null]);

            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $currency = Currency::tryFrom((string) ($data['currency'] ?? ''));
        if (null === $currency) {
            $this->logger->error('P24 notification with unsupported currency', ['currency' => $data['currency'] ?? null]);

            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $sessionId = (string) $data['sessionId'];
        $providerOrderId = (string) $data['orderId'];
        $amount = (int) $data['amount'];
        $methodId = isset($data['methodId']) ? (int) $data['methodId'] : null;

        $isNew = $this->notificationLog->record(
            provider: Przelewy24Gateway::PROVIDER,
            sessionId: $sessionId,
            providerOrderId: $providerOrderId,
            amount: $amount,
            currency: $currency->value,
            methodId: $methodId,
            signatureValid: true,
            rawPayload: $data,
        );

        // Duplicate notification (at-least-once delivery): already recorded, do not re-process.
        if ($isNew) {
            $this->commandBus->dispatch(new ConfirmPaymentFromProvider(
                orderId: $sessionId,
                providerOrderId: $providerOrderId,
                amountMinor: $amount,
                currency: $currency,
                method: null !== $methodId ? (string) $methodId : null,
            ));
        }

        return new Response('', Response::HTTP_OK);
    }
}
