<?php declare(strict_types=1);

namespace App\Tests\Commerce\Presentation;

use App\Commerce\Application\ConfirmPaymentFromProvider;
use App\Commerce\Application\ConfirmPaymentFromProviderHandler;
use App\Commerce\Application\InitiatePayment;
use App\Commerce\Application\PlaceOrder;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use App\User\Application\Command\RegisterUser;
use App\User\Domain\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Back-office payment analysis: admin-only access, the full event-audit view, and
 * the refund action.
 */
final class AdminPaymentsTest extends WebTestCase
{
    public function testNonAdminIsForbidden(): void
    {
        $client = self::createClient();
        $this->register('user@example.com');
        $this->login($client, 'user@example.com');

        $client->request('GET', 'https://localhost/admin/platnosci');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesPaymentsListAndAudit(): void
    {
        $client = self::createClient();
        $this->register('admin@example.com');
        $this->promote('admin@example.com');
        $orderId = $this->fulfilledOrder();
        $this->login($client, 'admin@example.com');

        $client->request('GET', 'https://localhost/admin/platnosci');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Zen PHP');

        $client->request('GET', 'https://localhost/admin/platnosci/' . $orderId);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'OrderPlaced');
        self::assertSelectorTextContains('body', 'OrderFulfilled');
        self::assertSelectorTextContains('body', 'zbilansowana');
    }

    public function testAdminCanRefund(): void
    {
        $client = self::createClient();
        $this->register('admin@example.com');
        $this->promote('admin@example.com');
        $orderId = $this->fulfilledOrder();
        $this->login($client, 'admin@example.com');

        $crawler = $client->request('GET', 'https://localhost/admin/platnosci/' . $orderId);
        $form = $crawler->selectButton('Wykonaj zwrot')->form(['reason' => 'test refund']);
        $client->submit($form);

        self::assertResponseRedirects();
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame('refunded', $connection->fetchOne('SELECT status FROM commerce_orders WHERE id = ?', [$orderId]));
    }

    private function fulfilledOrder(): string
    {
        $orderId = Uuid::v7()->toRfc4122();
        $bus = self::getContainer()->get(CommandBus::class);
        $bus->dispatch(new PlaceOrder(
            orderId: $orderId,
            buyerId: Uuid::v7()->toRfc4122(),
            ebookId: Uuid::v7()->toRfc4122(),
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

        return $orderId;
    }

    private function register(string $email): void
    {
        self::getContainer()->get(CommandBus::class)->dispatch(new RegisterUser(Uuid::v7()->toRfc4122(), $email, 'password123'));
    }

    private function promote(string $email): void
    {
        $users = self::getContainer()->get(UserRepository::class);
        $user = $users->byEmail($email);
        $user->promoteToAdmin();
        $users->add($user);
    }

    private function login(KernelBrowser $client, string $email): void
    {
        $client->request('GET', 'https://localhost/login');
        $client->submitForm('Zaloguj się', ['email' => $email, 'password' => 'password123']);
    }
}
