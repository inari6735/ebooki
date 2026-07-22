<?php declare(strict_types=1);

namespace App\Commerce\Domain\Order\Event;

use App\Shared\Application\Transport\SyncTransport;
use App\Shared\Domain\EventSourcing\DomainEvent;
use Symfony\Component\Uid\Uuid;

/**
 * A payment transaction was registered at the provider (Przelewy24) for this
 * order and the buyer is about to be redirected. `sessionId` is our own
 * correlation id sent to the provider (equal to the order id); `token` is the
 * provider's redirect token. No money has moved yet — confirmation only comes
 * from the verified provider notification ({@see PaymentConfirmed}).
 */
final readonly class PaymentInitiated implements DomainEvent, SyncTransport
{
    public function __construct(
        public Uuid $orderId,
        public string $provider,
        public string $sessionId,
        public string $token,
        private \DateTimeImmutable $initiatedAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->initiatedAt;
    }

    public function toPayload(): array
    {
        return [
            'orderId' => $this->orderId->toRfc4122(),
            'provider' => $this->provider,
            'sessionId' => $this->sessionId,
            'token' => $this->token,
            'initiatedAt' => $this->initiatedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['orderId']),
            $payload['provider'],
            $payload['sessionId'],
            $payload['token'],
            new \DateTimeImmutable($payload['initiatedAt']),
        );
    }
}
