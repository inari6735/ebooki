<?php declare(strict_types=1);

namespace App\Shared\Infrastructure\EventSourcing;

use App\Shared\Domain\EventSourcing\AggregateHistory;
use App\Shared\Domain\EventSourcing\DomainEvent;
use App\Shared\Domain\EventSourcing\EventMetadataProvider;
use App\Shared\Domain\EventSourcing\EventStore;
use App\Shared\Domain\EventSourcing\Exception\ConcurrencyConflict;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Doctrine DBAL adapter for the {@see EventStore}, backed by the append-only
 * `event_store` table. Uses the default connection, so appends join whatever
 * transaction the surrounding command handler opened (via the command bus's
 * `doctrine_transaction` middleware) — events and any synchronous projections
 * commit atomically together.
 *
 * Optimistic concurrency is enforced by the `UNIQUE(aggregate_id, version)`
 * index: a colliding version raises a unique-violation, surfaced as
 * {@see ConcurrencyConflict}.
 */
final readonly class DoctrineEventStore implements EventStore
{
    public function __construct(
        private Connection $connection,
        private EventSerializer $serializer,
        private EventMetadataProvider $metadata,
    ) {
    }

    public function append(string $aggregateType, Uuid $aggregateId, int $expectedVersion, array $events): void
    {
        if ([] === $events) {
            return;
        }

        $metadata = json_encode($this->metadata->current()->toArray(), \JSON_THROW_ON_ERROR);
        $version = $expectedVersion;

        try {
            foreach ($events as $event) {
                ++$version;
                $this->connection->insert('event_store', [
                    'aggregate_id' => $aggregateId->toRfc4122(),
                    'aggregate_type' => $aggregateType,
                    'version' => $version,
                    'event_type' => $this->serializer->typeOf($event),
                    'payload' => json_encode($this->serializer->payloadOf($event), \JSON_THROW_ON_ERROR),
                    'metadata' => $metadata,
                    'occurred_at' => $event->occurredAt()->format('Y-m-d H:i:s.uP'),
                ], [
                    'aggregate_id' => ParameterType::STRING,
                    'aggregate_type' => ParameterType::STRING,
                    'version' => ParameterType::INTEGER,
                    'event_type' => ParameterType::STRING,
                    'payload' => ParameterType::STRING,
                    'metadata' => ParameterType::STRING,
                    'occurred_at' => ParameterType::STRING,
                ]);
            }
        } catch (UniqueConstraintViolationException $e) {
            throw ConcurrencyConflict::forAggregate($aggregateId, $expectedVersion);
        }
    }

    public function load(Uuid $aggregateId): AggregateHistory
    {
        /** @var list<array{event_type: string, payload: string, version: int}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT event_type, payload, version FROM event_store WHERE aggregate_id = ? ORDER BY version ASC',
            [$aggregateId->toRfc4122()],
            [ParameterType::STRING],
        );

        $events = [];
        $version = 0;
        foreach ($rows as $row) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($row['payload'], true, 512, \JSON_THROW_ON_ERROR);
            $events[] = $this->serializer->deserialize($row['event_type'], $payload);
            $version = (int) $row['version'];
        }

        return new AggregateHistory($events, $version);
    }
}
