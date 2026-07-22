<?php declare(strict_types=1);

namespace App\Tests\Commerce\Presentation;

use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\Pricing\Pricing;
use App\Shared\Application\Bus\CommandBus;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use App\Tests\Commerce\Doubles\FakePaymentGateway;
use App\User\Application\Command\RegisterUser;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * "Buy now" places the order, registers the transaction with the (faked) provider
 * and redirects the buyer to the hosted payment page — leaving the order
 * AWAITING_PAYMENT until the webhook confirms it.
 */
final class CheckoutFlowTest extends WebTestCase
{
    public function testBuyNowPlacesOrderAndRedirectsToProvider(): void
    {
        $client = self::createClient();
        $sellerId = $this->register('seller@example.com');
        $this->register('buyer@example.com');
        $this->makeEbook('zen-php', $sellerId, 4000);
        $this->login($client, 'buyer@example.com');

        $crawler = $client->request('GET', 'https://localhost/ebook/zen-php');
        $form = $crawler->selectButton('Kup teraz')->form();
        $form['withdrawal_consent']->tick();
        $client->submit($form);

        // Redirected to the provider's hosted page.
        self::assertResponseStatusCodeSame(302);
        self::assertStringContainsString('przelewy24.pl/trnRequest/FAKE-', (string) $client->getResponse()->headers->get('Location'));

        // Order placed and moved to awaiting_payment; payment row initiated.
        $connection = self::getContainer()->get(Connection::class);
        $order = $connection->fetchAssociative('SELECT status, total_amount, seller_id FROM commerce_orders LIMIT 1');
        self::assertNotFalse($order);
        self::assertSame('awaiting_payment', $order['status']);
        self::assertSame(4000, (int) $order['total_amount']);
        self::assertSame($sellerId->toRfc4122(), $order['seller_id']);

        $payment = $connection->fetchAssociative('SELECT provider, status FROM commerce_payments LIMIT 1');
        self::assertNotFalse($payment);
        self::assertSame('przelewy24', $payment['provider']);
        self::assertSame('initiated', $payment['status']);

        $gateway = self::getContainer()->get(FakePaymentGateway::class);
        self::assertCount(1, $gateway->registered);
        self::assertSame(4000, $gateway->registered[0]->amount->amount);
    }

    public function testCheckoutRequiresConsent(): void
    {
        $client = self::createClient();
        $sellerId = $this->register('seller@example.com');
        $this->register('buyer@example.com');
        $this->makeEbook('zen-php', $sellerId, 4000);
        $this->login($client, 'buyer@example.com');

        $crawler = $client->request('GET', 'https://localhost/ebook/zen-php');
        $form = $crawler->selectButton('Kup teraz')->form();
        // consent left unchecked
        $client->submit($form);

        // Bounced back to the product page; no order created.
        self::assertResponseRedirects('/ebook/zen-php');
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM commerce_orders'));
    }

    public function testAnonymousBuyerIsSentToLogin(): void
    {
        $client = self::createClient();
        $sellerId = $this->register('seller@example.com');
        $this->makeEbook('zen-php', $sellerId, 4000);

        $client->request('POST', 'https://localhost/kup/zen-php');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $client->getResponse()->headers->get('Location'));
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

    private function makeEbook(string $slug, Uuid $ownerId, int $priceMinor): void
    {
        $ebook = new Ebook(Uuid::v7(), $ownerId, 'Zen PHP', $slug, 'Autor', 'pl', Pricing::fixed(Money::of($priceMinor, Currency::PLN)));
        $ebook->publish(new \DateTimeImmutable());
        self::getContainer()->get(EbookRepository::class)->save($ebook);
    }
}
