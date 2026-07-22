<?php declare(strict_types=1);

namespace App\Tests\Commerce\Presentation;

use App\Commerce\Application\ConfirmPaymentFromProvider;
use App\Commerce\Application\ConfirmPaymentFromProviderHandler;
use App\Commerce\Application\InitiatePayment;
use App\Commerce\Application\PlaceOrder;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use App\User\Application\Command\RegisterUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * The buyer's "Zakupione" panel page lists their completed purchases, with a
 * download for fulfilled ones.
 */
final class PurchasedTest extends WebTestCase
{
    public function testBuyerSeesPurchasedEbookWithDownload(): void
    {
        $client = self::createClient();
        $buyerId = $this->register('buyer@example.com');
        $ebookId = $this->fulfilledOrderFor($buyerId);
        $this->login($client, 'buyer@example.com');

        $client->request('GET', 'https://localhost/panel/zakupione');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Zen PHP');
        self::assertSelectorTextContains('body', 'Zrealizowane');
        self::assertSelectorExists('a[href="/pobierz/' . $ebookId . '"]');
    }

    public function testEmptyStateWhenNothingPurchased(): void
    {
        $client = self::createClient();
        $this->register('fresh@example.com');
        $this->login($client, 'fresh@example.com');

        $client->request('GET', 'https://localhost/panel/zakupione');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Nie masz jeszcze żadnych zakupów');
    }

    /** @return string ebookId of the fulfilled purchase */
    private function fulfilledOrderFor(Uuid $buyerId): string
    {
        $ebookId = Uuid::v7()->toRfc4122();
        $orderId = Uuid::v7()->toRfc4122();
        $bus = self::getContainer()->get(CommandBus::class);
        $bus->dispatch(new PlaceOrder(
            orderId: $orderId,
            buyerId: $buyerId->toRfc4122(),
            ebookId: $ebookId,
            sellerId: Uuid::v7()->toRfc4122(),
            title: 'Zen PHP',
            unitAmount: 2990,
            currency: Currency::PLN,
            commissionBps: 1000,
            withdrawalConsent: true,
        ));
        $bus->dispatch(new InitiatePayment($orderId, 'przelewy24', $orderId, 'TOKEN'));
        self::getContainer()->get(ConfirmPaymentFromProviderHandler::class)(
            new ConfirmPaymentFromProvider($orderId, 'P24-1', 2990, Currency::PLN, '25'),
        );

        return $ebookId;
    }

    private function register(string $email): Uuid
    {
        $id = Uuid::v7();
        self::getContainer()->get(CommandBus::class)->dispatch(new RegisterUser($id->toRfc4122(), $email, 'password123'));

        return $id;
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $client->request('GET', 'https://localhost/login');
        $client->submitForm('Zaloguj się', ['email' => $email, 'password' => 'password123']);
    }
}
