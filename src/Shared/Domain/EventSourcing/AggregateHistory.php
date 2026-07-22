<?php declare(strict_types=1);

namespace App\Shared\Domain\EventSourcing;

/**
 * The stored event stream of a single aggregate, as read back from the
 * {@see EventStore}: the ordered events plus the version they add up to (used as
 * the expected version for the next optimistic-locked append).
 */
final readonly class AggregateHistory
{
    /** @param list<DomainEvent> $events */
    public function __construct(
        public array $events,
        public int $version,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->events;
    }
}
