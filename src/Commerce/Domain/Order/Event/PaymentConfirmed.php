<?php declare(strict_types=1);

namespace App\Commerce\Domain\Order\Event;

use App\Shared\Application\Transport\SyncTransport;
use App\Shared\Domain\EventSourcing\DomainEvent;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * The provider notification was verified (Przelewy24 "verify" call succeeded) and
 * the buyer's payment is confirmed. This is the point money is real; fulfilment
 * and ledger postings react to this event. `providerOrderId` is P24's own order
 * id, kept for reconciliation.
 */
final readonly class PaymentConfirmed implements DomainEvent, SyncTransport
{
    public function __construct(
        public Uuid $orderId,
        public string $providerOrderId,
        public Money $paidAmount,
        public ?string $method,
        private \DateTimeImmutable $confirmedAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function toPayload(): array
    {
        return [
            'orderId' => $this->orderId->toRfc4122(),
            'providerOrderId' => $this->providerOrderId,
            'currency' => $this->paidAmount->currency->value,
            'paidAmount' => $this->paidAmount->amount,
            'method' => $this->method,
            'confirmedAt' => $this->confirmedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new self(
            Uuid::fromString($payload['orderId']),
            $payload['providerOrderId'],
            Money::of($payload['paidAmount'], Currency::from($payload['currency'])),
            $payload['method'],
            new \DateTimeImmutable($payload['confirmedAt']),
        );
    }
}
