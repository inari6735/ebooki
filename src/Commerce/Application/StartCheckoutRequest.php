<?php declare(strict_types=1);

namespace App\Commerce\Application;

use App\Shared\Domain\Money\Money;
use Symfony\Component\Uid\Uuid;

/**
 * Input to {@see StartCheckout}: the server-computed facts of a "buy now". The
 * amount and commission are already resolved from the catalog/pricing by the
 * caller (never taken from the browser).
 */
final readonly class StartCheckoutRequest
{
    public function __construct(
        public Uuid $ebookId,
        public Uuid $sellerId,
        public Uuid $buyerId,
        public string $title,
        public Money $amount,
        public int $commissionBps,
        public string $buyerEmail,
        public bool $withdrawalConsent,
    ) {
    }
}
