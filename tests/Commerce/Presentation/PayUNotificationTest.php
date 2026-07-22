<?php declare(strict_types=1);

namespace App\Tests\Commerce\Presentation;

use App\Commerce\Application\ConfirmPaymentFromProvider;
use App\Commerce\Infrastructure\Payment\PayU\PayUConfig;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * PayU notification webhook: verify OpenPayU-Signature, log + dedupe, dispatch
 * async confirmation only on COMPLETED. In the test env PAYU_SECOND_KEY is empty,
 * so signatures are computed with the container's configured key.
 */
final class PayUNotificationTest extends WebTestCase
{
    /** @return array<string, mixed> */
    private function notification(string $sessionId, string $status = 'COMPLETED'): array
    {
        return ['order' => [
            'orderId' => 'PAYU-9',
            'extOrderId' => $sessionId,
            'status' => $status,
            'totalAmount' => '2990',
            'currencyCode' => 'PLN',
        ]];
    }

    private function post(KernelBrowser $client, array $payload, bool $tamper = false): void
    {
        $secondKey = self::getContainer()->get(PayUConfig::class)->secondKey;
        $raw = json_encode($payload);
        $sig = $tamper ? 'deadbeef' : md5($raw . $secondKey);
        $client->request('POST', 'https://localhost/platnosc/payu/notify', [], [], [
            'HTTP_OPENPAYU_SIGNATURE' => "sender=checkout;signature={$sig};algorithm=MD5;content=DOCUMENT",
            'CONTENT_TYPE' => 'application/json',
        ], $raw);
    }

    public function testCompletedNotificationIsLoggedAndDispatchesConfirmation(): void
    {
        $client = self::createClient();
        $sessionId = Uuid::v7()->toRfc4122();

        $this->post($client, $this->notification($sessionId));

        self::assertResponseIsSuccessful();

        $connection = self::getContainer()->get(Connection::class);
        $row = $connection->fetchAssociative('SELECT provider, signature_valid, provider_order_id FROM payment_notifications WHERE session_id = ?', [$sessionId]);
        self::assertNotFalse($row);
        self::assertSame('payu', $row['provider']);
        self::assertTrue((bool) $row['signature_valid']);
        self::assertSame('PAYU-9', $row['provider_order_id']);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertCount(1, $transport->getSent());
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(ConfirmPaymentFromProvider::class, $message);
        self::assertSame($sessionId, $message->orderId);
        self::assertSame(2990, $message->amountMinor);
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $client = self::createClient();
        $sessionId = Uuid::v7()->toRfc4122();

        $this->post($client, $this->notification($sessionId), tamper: true);

        self::assertResponseStatusCodeSame(400);
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM payment_notifications WHERE session_id = ?', [$sessionId]));
    }

    public function testPendingStatusIsAcknowledgedButNotProcessed(): void
    {
        $client = self::createClient();
        $sessionId = Uuid::v7()->toRfc4122();

        $this->post($client, $this->notification($sessionId, 'PENDING'));

        self::assertResponseIsSuccessful();
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM payment_notifications WHERE session_id = ?', [$sessionId]));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertCount(0, $transport->getSent());
    }

    public function testDuplicateCompletedIsDeduplicated(): void
    {
        $client = self::createClient();
        $sessionId = Uuid::v7()->toRfc4122();
        $payload = $this->notification($sessionId);

        $this->post($client, $payload);
        $this->post($client, $payload); // redelivery

        self::assertResponseIsSuccessful();
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM payment_notifications WHERE session_id = ?', [$sessionId]));

        // Transport is reset per request → reflects only the 2nd (duplicate) request.
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertCount(0, $transport->getSent(), 'A redelivered notification must not dispatch again.');
    }
}
