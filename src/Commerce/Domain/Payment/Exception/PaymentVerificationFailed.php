<?php declare(strict_types=1);

namespace App\Commerce\Domain\Payment\Exception;

/**
 * Verification against the provider did not confirm the payment (rejected,
 * amount/currency mismatch, unknown transaction, transport error). The order must
 * NOT be treated as paid.
 */
final class PaymentVerificationFailed extends \RuntimeException
{
}
