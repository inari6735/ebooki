<?php declare(strict_types=1);

namespace App\Commerce\Presentation;

use App\Commerce\Application\StartCheckout;
use App\Commerce\Application\StartCheckoutRequest;
use App\Commerce\Domain\Payment\Exception\PaymentRegistrationFailed;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\EbookStatus;
use App\User\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Buy now" entry point. Validates the sale server-side (published eBook, real
 * positive price computed from Pricing, buyer consent), then hands off to
 * {@see StartCheckout} which places the order and registers the Przelewy24
 * transaction, and redirects the buyer to the hosted payment page.
 *
 * The price is ALWAYS taken from the eBook's Pricing, never from the request.
 */
#[IsGranted('ROLE_USER')]
final class CheckoutController extends AbstractController
{
    public function __construct(
        private readonly EbookRepository $ebooks,
        private readonly StartCheckout $startCheckout,
        private readonly int $commissionBps,
    ) {
    }

    #[Route('/kup/{slug}', name: 'app_checkout', methods: ['POST'])]
    public function __invoke(string $slug, Request $request): Response
    {
        $ebook = $this->ebooks->findBySlug($slug);
        if (null === $ebook || EbookStatus::PUBLISHED !== $ebook->getStatus()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('checkout', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        /** @var User $user */
        $user = $this->getUser();

        if ($ebook->getOwnerId()->equals($user->getId())) {
            $this->addFlash('error', 'Nie możesz kupić własnego eBooka.');

            return $this->redirectToRoute('app_ebook_show', ['slug' => $slug]);
        }

        if (!$request->request->getBoolean('withdrawal_consent')) {
            $this->addFlash('error', 'Aby kupić, potwierdź zgodę na natychmiastowy dostęp do treści cyfrowej.');

            return $this->redirectToRoute('app_ebook_show', ['slug' => $slug]);
        }

        $amount = $ebook->pricing()->currentPrice();
        if (null === $amount || !$amount->isPositive()) {
            // Free / pay-what-you-want are not part of the paid-checkout MVP.
            $this->addFlash('error', 'Ten eBook nie jest dostępny do zakupu w ten sposób.');

            return $this->redirectToRoute('app_ebook_show', ['slug' => $slug]);
        }

        try {
            $redirectUrl = ($this->startCheckout)(new StartCheckoutRequest(
                ebookId: $ebook->getId(),
                sellerId: $ebook->getOwnerId(),
                buyerId: $user->getId(),
                title: $ebook->getTitle(),
                amount: $amount,
                commissionBps: $this->commissionBps,
                buyerEmail: $user->getEmail(),
                withdrawalConsent: true,
                buyerIp: $request->getClientIp() ?? '127.0.0.1',
            ));
        } catch (PaymentRegistrationFailed) {
            $this->addFlash('error', 'Nie udało się rozpocząć płatności. Spróbuj ponownie za chwilę.');

            return $this->redirectToRoute('app_ebook_show', ['slug' => $slug]);
        }

        return $this->redirect($redirectUrl);
    }
}
