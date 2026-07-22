<?php declare(strict_types=1);

namespace App\Commerce\Domain\Order\Event;

use App\Shared\Application\Transport\SyncTransport;
use App\Shared\Domain\EventSourcing\DomainEvent;
use Symfony\Component\Uid\Uuid;

/**
 * Access to the purchased eBook was granted to the buyer. For a digital good this
 * follows immediately after {@see PaymentConfirmed}. Carries buyer + eBook so the
 * entitlement read model can be built without looking anything else up.
 */
final readonly class OrderFulfilled implements DomainEvent, SyncTransport
{
    public function __construct(
        public Uuid $orderId,
        public Uuid $buyerId,
        public Uuid $ebookId,
        private \DateTimeImmutable $fulfilledAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->fulfilledAt;
    }

    public function toPayload(): array
    {
        return [
            'orderId' => $this->orderId->toRfc4122(),
            'buyerId' => $this->buyerId->toRfc4122(),
            'ebookId' => $this->ebookId->toRfc4122(),
            'fulfilledAt' => $this->fulfilledAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['orderId']),
            Uuid::fromString($payload['buyerId']),
            Uuid::fromString($payload['ebookId']),
            new \DateTimeImmutable($payload['fulfilledAt']),
        );
    }
}
