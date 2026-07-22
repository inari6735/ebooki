<?php declare(strict_types=1);

namespace App\Shared\Domain\EventSourcing;

/**
 * Ambient audit context captured alongside every stored event — WHO caused it
 * and in WHICH request/flow — so the full history of any aggregate can be traced
 * back and correlated. It is not part of the domain fact itself (that lives in
 * the event payload); it is provenance for auditing and reconciliation.
 *
 * `correlationId` groups every event produced while handling one originating
 * action; `causationId` points at the message that directly caused this event.
 */
final readonly class EventMetadata
{
    public function __construct(
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $actorId = null,
        public ?string $ip = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'correlationId' => $this->correlationId,
            'causationId' => $this->causationId,
            'actorId' => $this->actorId,
            'ip' => $this->ip,
        ], static fn (mixed $v): bool => null !== $v);
    }
}
