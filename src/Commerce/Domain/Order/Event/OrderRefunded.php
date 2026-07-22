<?php declare(strict_types=1);

namespace App\Commerce\Domain\Order\Event;

use App\Shared\Application\Transport\SyncTransport;
use App\Shared\Domain\EventSourcing\DomainEvent;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * The order was refunded (full refund in the MVP). Reverses the money trail and
 * revokes the buyer's entitlement. Carries the reason for auditing.
 */
final readonly class OrderRefunded implements DomainEvent, SyncTransport
{
    public function __construct(
        public Uuid $orderId,
        public Money $amount,
        public string $reason,
        private \DateTimeImmutable $refundedAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->refundedAt;
    }

    public function toPayload(): array
    {
        return [
            'orderId' => $this->orderId->toRfc4122(),
            'currency' => $this->amount->currency->value,
            'amount' => $this->amount->amount,
            'reason' => $this->reason,
            'refundedAt' => $this->refundedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['orderId']),
            Money::of($payload['amount'], Currency::from($payload['currency'])),
            $payload['reason'],
            new \DateTimeImmutable($payload['refundedAt']),
        );
    }
}
