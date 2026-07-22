<?php declare(strict_types=1);

namespace App\Commerce\Domain\Order\Event;

use App\Shared\Application\Transport\SyncTransport;
use App\Shared\Domain\EventSourcing\DomainEvent;
use App\Shared\Domain\Money\Currency;
use App\Shared\Domain\Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * A buyer placed an order for one eBook. This is the immutable financial snapshot
 * of the sale — unit price, the author/platform split and the commission rate are
 * frozen here and never recomputed from the catalog afterwards.
 *
 * Implements {@see SyncTransport}: the read-model projection must be consistent
 * the moment the order exists (the buyer is redirected to pay immediately), so it
 * runs in-process within the placing transaction.
 */
final readonly class OrderPlaced implements DomainEvent, SyncTransport
{
    public function __construct(
        public Uuid $orderId,
        public Uuid $buyerId,
        public Uuid $ebookId,
        public Uuid $sellerId,
        public string $title,
        public Money $unitPrice,
        public Money $authorEarnings,
        public Money $platformFee,
        public int $commissionBps,
        public bool $withdrawalConsent,
        private \DateTimeImmutable $placedAt,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function toPayload(): array
    {
        return [
            'orderId' => $this->orderId->toRfc4122(),
            'buyerId' => $this->buyerId->toRfc4122(),
            'ebookId' => $this->ebookId->toRfc4122(),
            'sellerId' => $this->sellerId->toRfc4122(),
            'title' => $this->title,
            'currency' => $this->unitPrice->currency->value,
            'unitAmount' => $this->unitPrice->amount,
            'authorEarnings' => $this->authorEarnings->amount,
            'platformFee' => $this->platformFee->amount,
            'commissionBps' => $this->commissionBps,
            'withdrawalConsent' => $this->withdrawalConsent,
            'placedAt' => $this->placedAt->format('Y-m-d\TH:i:s.uP'),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        $currency = Currency::from($payload['currency']);

        return new self(
            Uuid::fromString($payload['orderId']),
            Uuid::fromString($payload['buyerId']),
            Uuid::fromString($payload['ebookId']),
            Uuid::fromString($payload['sellerId']),
            $payload['title'],
            Money::of($payload['unitAmount'], $currency),
            Money::of($payload['authorEarnings'], $currency),
            Money::of($payload['platformFee'], $currency),
            $payload['commissionBps'],
            $payload['withdrawalConsent'],
            new \DateTimeImmutable($payload['placedAt']),
        );
    }
}
