<?php declare(strict_types=1);

namespace App\Commerce\Domain\Order\Event;

use App\Shared\Application\Transport\SyncTransport;
use App\Shared\Domain\EventSourcing\DomainEvent;
use Symfony\Component\Uid\Uuid;

/**
 * The payment did not go through (provider rejected it, verification failed, or
 * the transaction was cancelled). The order carries the reason for auditing.
 */
final readonly class PaymentFailed implements DomainEvent, SyncTransport
{
    public function __construct(
        public Uuid $orderId,
        public string $reason,
        private \DateTimeImmutable $failedAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function toPayload(): array
    {
        return [
            'orderId' => $this->orderId->toRfc4122(),
            'reason' => $this->reason,
            'failedAt' => $this->failedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['orderId']),
            $payload['reason'],
            new \DateTimeImmutable($payload['failedAt']),
        );
    }
}
