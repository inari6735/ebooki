<?php declare(strict_types=1);

namespace App\Shared\Domain\EventSourcing;

use App\Shared\Domain\EventSourcing\Exception\ConcurrencyConflict;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only store of domain events — the system of record for every
 * event-sourced aggregate. Port only: the adapter (DoctrineEventStore) lives in
 * Infrastructure. Never expose an update or delete; history is immutable.
 */
interface EventStore
{
    /**
     * Append events to an aggregate's stream under optimistic concurrency.
     * The stream must currently be at exactly $expectedVersion, otherwise a
     * concurrent write happened and {@see ConcurrencyConflict} is thrown; the
     * caller reloads and retries.
     *
     * @param non-empty-string $aggregateType stable logical name of the aggregate (e.g. "commerce.order")
     * @param list<DomainEvent> $events        events to append, in order
     *
     * @throws ConcurrencyConflict
     */
    public function append(string $aggregateType, Uuid $aggregateId, int $expectedVersion, array $events): void;

    /** Load the full ordered history of one aggregate (empty if it does not exist). */
    public function load(Uuid $aggregateId): AggregateHistory;
}
