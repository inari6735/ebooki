<?php declare(strict_types=1);

namespace App\Shared\Infrastructure\EventSourcing;

use App\Shared\Domain\EventSourcing\DomainEvent;
use App\Shared\Domain\EventSourcing\Exception\CorruptEventStream;

/**
 * Maps {@see DomainEvent} objects to/from the two columns the event store
 * persists: a stable string `type` and a JSON `payload`.
 *
 * The stored type is the event's fully-qualified class name — precise and
 * self-describing when auditing the raw table. Refactor safety without ever
 * touching the mechanism: if an event class is later moved or renamed, register
 * the old name in $aliases (old FQCN => new FQCN) so historical rows still
 * deserialize. New events need no registration at all — implementing
 * {@see DomainEvent} is enough.
 */
final readonly class EventSerializer
{
    /** @param array<string, class-string<DomainEvent>> $aliases legacy stored type => current class */
    public function __construct(private array $aliases = [])
    {
    }

    public function typeOf(DomainEvent $event): string
    {
        return $event::class;
    }

    /** @return array<string, mixed> */
    public function payloadOf(DomainEvent $event): array
    {
        return $event->toPayload();
    }

    /** @param array<string, mixed> $payload */
    public function deserialize(string $type, array $payload): DomainEvent
    {
        $class = $this->aliases[$type] ?? $type;

        if (!is_a($class, DomainEvent::class, true)) {
            throw new CorruptEventStream(sprintf('Stored event type "%s" is not a known domain event.', $type));
        }

        return $class::fromPayload($payload);
    }
}
