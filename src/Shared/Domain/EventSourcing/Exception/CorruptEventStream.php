<?php declare(strict_types=1);

namespace App\Shared\Domain\EventSourcing\Exception;

/**
 * Thrown when a stored stream cannot be turned back into an aggregate: an
 * unknown/unmapped event type, or an event with no `apply*` mutator. Always a
 * programming/deployment error, never expected at runtime.
 */
final class CorruptEventStream extends \RuntimeException
{
}
