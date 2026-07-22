<?php declare(strict_types=1);

namespace App\Commerce\Domain\Payment\Exception;

/**
 * The provider refused or failed to register the transaction; the buyer cannot be
 * sent to pay. Never let this be mistaken for a completed payment.
 */
final class PaymentRegistrationFailed extends \RuntimeException
{
}
