<?php declare(strict_types=1);

namespace App\Shared\Domain\EventSourcing;

use App\Shared\Domain\EventSourcing\Exception\CorruptEventStream;
use Symfony\Component\Uid\Uuid;

/**
 * Base class for event-sourced aggregates. State is NEVER mutated directly:
 * behaviour methods validate invariants and then {@see recordThat()} a
 * {@see DomainEvent}, which is applied to `this` and buffered for persistence.
 * The same `apply*` methods rebuild state when the aggregate is
 * {@see reconstituteFromHistory() replayed} from the event store — so there is
 * exactly one place per event where state changes, and history and live writes
 * can never diverge.
 *
 * This is the reusable brick: concrete aggregates only add behaviour + `apply*`
 * methods; loading, versioning (optimistic concurrency) and event collection are
 * handled here and never need touching.
 */
abstract class AggregateRoot
{
    /** @var list<DomainEvent> events recorded since load, awaiting persistence */
    private array $recordedEvents = [];

    /** Number of events already persisted (the expected version for the next append). */
    private int $aggregateVersion = 0;

    /** The aggregate's identity — set by the first applied event. */
    abstract public function aggregateId(): Uuid;

    /**
     * Rebuild an aggregate purely from its recorded history. Bypasses the
     * constructor (creation is a recorded event, not a replayed one).
     *
     * @param iterable<DomainEvent> $events
     */
    final public static function reconstituteFromHistory(iterable $events): static
    {
        /** @var static $aggregate */
        $aggregate = (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();

        foreach ($events as $event) {
            $aggregate->applyEvent($event);
            ++$aggregate->aggregateVersion;
        }

        if (0 === $aggregate->aggregateVersion) {
            throw new CorruptEventStream(sprintf('Cannot reconstitute %s from an empty history.', static::class));
        }

        return $aggregate;
    }

    /** Record a new fact: apply it to state and buffer it for the event store. */
    final protected function recordThat(DomainEvent $event): void
    {
        $this->applyEvent($event);
        $this->recordedEvents[] = $event;
    }

    /** Hand off buffered events for persistence, clearing the buffer. */
    final public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    /** Version the store must find to accept the next append (optimistic locking). */
    final public function aggregateVersion(): int
    {
        return $this->aggregateVersion;
    }

    /**
     * Route an event to its `apply{ShortEventName}` mutator. A missing mutator is
     * a programming error (an event with no state transition) and must fail loud.
     */
    private function applyEvent(DomainEvent $event): void
    {
        $method = 'apply' . (new \ReflectionClass($event))->getShortName();

        if (!method_exists($this, $method)) {
            throw new CorruptEventStream(sprintf('%s cannot apply %s: missing %s().', static::class, $event::class, $method));
        }

        $this->$method($event);
    }
}
