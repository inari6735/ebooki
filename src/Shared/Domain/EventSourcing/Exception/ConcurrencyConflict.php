<?php declare(strict_types=1);

namespace App\Shared\Domain\EventSourcing\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * Thrown when an append finds the stream at a different version than expected —
 * a concurrent writer got there first. The command should reload the aggregate
 * and retry (or surface a conflict to the user). Guarantees no lost updates.
 */
final class ConcurrencyConflict extends \RuntimeException
{
    public static function forAggregate(Uuid $aggregateId, int $expectedVersion): self
    {
        return new self(sprintf(
            'Concurrency conflict on aggregate %s: expected version %d was already superseded.',
            $aggregateId->toRfc4122(),
            $expectedVersion,
        ));
    }
}
