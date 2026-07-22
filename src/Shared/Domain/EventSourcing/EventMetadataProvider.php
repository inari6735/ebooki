<?php declare(strict_types=1);

namespace App\Shared\Domain\EventSourcing;

/**
 * Supplies the ambient {@see EventMetadata} for events being appended right now
 * (current user, request, correlation). Port only; the request/security-aware
 * adapter lives in Infrastructure, so the event store stays free of framework
 * concerns and is trivially fakeable in tests.
 */
interface EventMetadataProvider
{
    public function current(): EventMetadata;
}
