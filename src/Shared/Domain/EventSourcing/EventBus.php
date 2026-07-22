<?php declare(strict_types=1);

namespace App\Shared\Domain\EventSourcing;

/**
 * Publishes persisted domain events to their handlers (projections, reactors).
 * Port only; the Messenger-backed adapter lives in Infrastructure.
 *
 * Delivery is decided per event by the transport marker it implements
 * ({@see \App\Shared\Application\Transport\SyncTransport} → handled in-process,
 * inside the current transaction, for consistency-critical read models;
 * {@see \App\Shared\Application\Transport\AsyncTransport} → queued to the worker
 * for side effects like e-mails or provider calls). An event with neither marker
 * is handled synchronously in-process.
 */
interface EventBus
{
    public function publish(DomainEvent $event): void;
}
