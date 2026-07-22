<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Commerce\Domain\Payment\PaymentGateway;
use App\Commerce\Domain\Payment\PaymentRegistration;
use App\Shared\Application\Bus\CommandBus;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Orchestrates a single "buy now": place the order (transactional command),
 * register the transaction at the provider (HTTP, deliberately OUTSIDE any DB
 * transaction), then record payment initiation and hand back the redirect URL.
 * Keeping the provider call between two separate commands ensures no external
 * HTTP happens while a database transaction is held open.
 *
 * The session id sent to the provider is the order id, so the webhook can map a
 * notification straight back to the order.
 */
final readonly class StartCheckout
{
    /** Provider → its server-to-server notification route. */
    private const array NOTIFY_ROUTES = [
        'przelewy24' => 'app_p24_status',
        'payu' => 'app_payu_notify',
    ];

    public function __construct(
        private CommandBus $commandBus,
        private PaymentGateway $gateway,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(StartCheckoutRequest $request): string
    {
        $orderId = Uuid::v7();
        $sessionId = $orderId->toRfc4122();

        $this->commandBus->dispatch(new PlaceOrder(
            orderId: $sessionId,
            buyerId: $request->buyerId->toRfc4122(),
            ebookId: $request->ebookId->toRfc4122(),
            sellerId: $request->sellerId->toRfc4122(),
            title: $request->title,
            unitAmount: $request->amount->amount,
            currency: $request->amount->currency,
            commissionBps: $request->commissionBps,
            withdrawalConsent: $request->withdrawalConsent,
        ));

        $notifyRoute = self::NOTIFY_ROUTES[$this->gateway->provider()]
            ?? throw new \LogicException(sprintf('No notification route configured for provider "%s".', $this->gateway->provider()));

        $registered = $this->gateway->register(new PaymentRegistration(
            sessionId: $sessionId,
            amount: $request->amount,
            description: mb_substr('eBook: ' . $request->title, 0, 250),
            buyerEmail: $request->buyerEmail,
            urlReturn: $this->urlGenerator->generate('app_checkout_return', ['orderId' => $sessionId], UrlGeneratorInterface::ABSOLUTE_URL),
            urlStatus: $this->urlGenerator->generate($notifyRoute, [], UrlGeneratorInterface::ABSOLUTE_URL),
            buyerIp: $request->buyerIp,
        ));

        $this->commandBus->dispatch(new InitiatePayment(
            orderId: $sessionId,
            provider: $this->gateway->provider(),
            sessionId: $sessionId,
            token: $registered->token,
        ));

        return $registered->redirectUrl;
    }
}
