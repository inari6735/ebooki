<?php declare(strict_types=1);

namespace App\Commerce\Domain\Payment\Exception;

/**
 * The provider refused or failed to process the refund. The order must NOT be
 * marked refunded.
 */
final class PaymentRefundFailed extends \RuntimeException
{
}
