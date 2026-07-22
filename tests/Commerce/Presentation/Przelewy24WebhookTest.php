<?php declare(strict_types=1);

namespace App\Tests\Commerce\Presentation;

use App\Commerce\Application\ConfirmPaymentFromProvider;
use App\Commerce\Infrastructure\Payment\Przelewy24\P24Signer;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * The Przelewy24 `urlStatus` webhook: verify signature, log the raw notification,
 * dispatch async confirmation, dedupe redeliveries — all without granting access
 * itself. In the test env P24_MERCHANT_ID/POS_ID are 0 and P24_CRC is empty, so a
 * valid signature is computed with the container's signer.
 */
final class Przelewy24WebhookTest extends WebTestCase
{
    /** @return array<string, mixed> */
    private function signedNotification(string $sessionId): array
    {
        $signer = self::getContainer()->get(P24Signer::class);
        $notification = [
            'merchantId' => 0,
            'posId' => 0,
            'sessionId' => $sessionId,
            'amount' => 2990,
            'originAmount' => 2990,
            'currency' => 'PLN',
            'orderId' => 98765,
            'methodId' => 25,
            'statement' => 'pay',
        ];
        $notification['sign'] = $signer->forNotification(0, 0, $sessionId, 2990, 2990, 'PLN', 98765, 25, 'pay');

        return $notification;
    }

    private function post(object $client, array $payload): void
    {
        $client->request('POST', 'https://localhost/platnosc/przelewy24/status', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    }

    public function testValidNotificationIsLoggedAndDispatchesConfirmation(): void
    {
        $client = self::createClient();
        $sessionId = Uuid::v7()->toRfc4122();

        $this->post($client, $this->signedNotification($sessionId));

        self::assertResponseIsSuccessful();

        $connection = self::getContainer()->get(Connection::class);
        $row = $connection->fetchAssociative('SELECT signature_valid, provider_order_id FROM payment_notifications WHERE session_id = ?', [$sessionId]);
        self::assertNotFalse($row);
        self::assertTrue((bool) $row['signature_valid']);
        self::assertSame('98765', $row['provider_order_id']);

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
        $payload = $this->signedNotification($sessionId);
        $payload['sign'] = 'tampered';

        $this->post($client, $payload);

        self::assertResponseStatusCodeSame(400);
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM payment_notifications WHERE session_id = ?', [$sessionId]));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertCount(0, $transport->getSent());
    }

    public function testDuplicateNotificationIsDeduplicated(): void
    {
        $client = self::createClient();
        $sessionId = Uuid::v7()->toRfc4122();
        $payload = $this->signedNotification($sessionId);

        $this->post($client, $payload);
        $this->post($client, $payload); // redelivery

        self::assertResponseIsSuccessful();

        // The raw log deduped to a single row (its UNIQUE index is the idempotency guard).
        $connection = self::getContainer()->get(Connection::class);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM payment_notifications WHERE session_id = ?', [$sessionId]));

        // The transport is reset per request, so this reflects only the SECOND
        // (duplicate) request — which must have dispatched nothing.
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertCount(0, $transport->getSent(), 'A redelivered notification must not dispatch confirmation again.');
    }
}
