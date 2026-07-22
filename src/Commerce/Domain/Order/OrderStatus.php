<?php declare(strict_types=1);

namespace App\Commerce\Domain\Order;

/**
 * Lifecycle of a single "buy now" order, derived purely from its event stream.
 * MVP reaches PENDING (order placed, awaiting payment); the payment/fulfilment
 * transitions arrive with the Przelewy24 integration (see
 * docs/commerce/payments-architecture.md).
 */
enum OrderStatus: string
{
    case PENDING = 'pending';                   // placed, payment not started yet
    case AWAITING_PAYMENT = 'awaiting_payment'; // registered at the provider, buyer redirected
    case PAID = 'paid';                         // payment verified
    case FULFILLED = 'fulfilled';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
    case REFUNDED = 'refunded';
}
