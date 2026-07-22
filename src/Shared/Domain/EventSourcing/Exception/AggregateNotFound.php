<?php declare(strict_types=1);

namespace App\Shared\Domain\EventSourcing\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * Thrown when an aggregate is loaded by id but has no stored history.
 */
final class AggregateNotFound extends \RuntimeException
{
    public static function of(string $aggregateType, Uuid $aggregateId): self
    {
        return new self(sprintf('%s %s was not found.', $aggregateType, $aggregateId->toRfc4122()));
    }
}
