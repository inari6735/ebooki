<?php declare(strict_types=1);

namespace App\Shared\Domain\EventSourcing;

/**
 * A fact that happened in the past inside an {@see AggregateRoot}. Events are
 * immutable, past-tense, and are the ONLY source of truth for the aggregate's
 * state — everything else (read models/projections) is derived from the stream
 * of these.
 *
 * Each event owns its own serialization ({@see toPayload}/{@see fromPayload}) so
 * the event store stays a dumb, generic mechanism and never needs to learn about
 * concrete event shapes. The payload MUST be a JSON-serializable array of scalars
 * (money as integer minor units, ids/uuids as strings, dates as ISO-8601, …) —
 * never rely on PHP object graphs surviving a round-trip.
 *
 * Concrete events additionally declare how they should be delivered to their
 * handlers by implementing one of the transport markers
 * ({@see \App\Shared\Application\Transport\SyncTransport} for consistency-critical
 * projections, {@see \App\Shared\Application\Transport\AsyncTransport} for
 * fire-and-forget reactions) — the event bus routes on that marker.
 */
interface DomainEvent
{
    /** When the fact occurred (set once, at construction; preserved across (de)serialization). */
    public function occurredAt(): \DateTimeImmutable;

    /** @return array<string, mixed> JSON-serializable representation stored verbatim in the event store. */
    public function toPayload(): array;

    /** @param array<string, mixed> $payload The array produced by {@see toPayload}. */
    public static function fromPayload(array $payload): static;
}
