<?php declare(strict_types=1);

namespace App\Shared\Infrastructure\EventSourcing;

use App\Shared\Domain\EventSourcing\AggregateRoot;
use App\Shared\Domain\EventSourcing\EventBus;
use App\Shared\Domain\EventSourcing\EventStore;
use App\Shared\Domain\EventSourcing\Exception\AggregateNotFound;
use Symfony\Component\Uid\Uuid;

/**
 * Reusable base for event-sourced repositories: rebuild an aggregate from its
 * stored history, and persist newly recorded events + publish them to
 * projections/reactors. Concrete repositories only declare which aggregate they
 * manage; the load/save mechanics never need touching.
 *
 * @template T of AggregateRoot
 */
abstract class EventSourcedAggregateRepository
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly EventBus $eventBus,
    ) {
    }

    /** Stable logical stream name for this aggregate, e.g. "commerce.order". */
    abstract protected function aggregateType(): string;

    /** @return class-string<T> */
    abstract protected function aggregateClass(): string;

    /**
     * @return T
     *
     * @throws AggregateNotFound
     */
    protected function load(Uuid $id): AggregateRoot
    {
        $aggregate = $this->find($id);

        if (null === $aggregate) {
            throw AggregateNotFound::of($this->aggregateType(), $id);
        }

        return $aggregate;
    }

    /** @return T|null */
    protected function find(Uuid $id): ?AggregateRoot
    {
        $history = $this->eventStore->load($id);

        if ($history->isEmpty()) {
            return null;
        }

        return ($this->aggregateClass())::reconstituteFromHistory($history->events);
    }

    /** @param T $aggregate */
    protected function persist(AggregateRoot $aggregate): void
    {
        $events = $aggregate->releaseEvents();

        if ([] === $events) {
            return;
        }

        $this->eventStore->append(
            $this->aggregateType(),
            $aggregate->aggregateId(),
            $aggregate->aggregateVersion(),
            $events,
        );

        foreach ($events as $event) {
            $this->eventBus->publish($event);
        }
    }
}
