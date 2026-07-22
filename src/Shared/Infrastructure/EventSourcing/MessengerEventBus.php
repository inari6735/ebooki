<?php declare(strict_types=1);

namespace App\Shared\Infrastructure\EventSourcing;

use App\Shared\Domain\EventSourcing\DomainEvent;
use App\Shared\Domain\EventSourcing\EventBus;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Publishes domain events on the dedicated event bus (`messenger.bus.event`).
 * That bus carries NO doctrine_transaction middleware, so synchronous handlers
 * (projections) run inside the transaction already opened by the command bus.
 * Routing to sync vs. async transport is decided by the transport marker on each
 * event (see {@see EventBus}). Unwraps HandlerFailedException so a failing
 * synchronous projection surfaces its real cause to the command handler.
 */
final readonly class MessengerEventBus implements EventBus
{
    public function __construct(private MessageBusInterface $eventBus)
    {
    }

    public function publish(DomainEvent $event): void
    {
        try {
            $this->eventBus->dispatch($event);
        } catch (HandlerFailedException $e) {
            throw $e->getPrevious() ?? $e;
        }
    }
}
