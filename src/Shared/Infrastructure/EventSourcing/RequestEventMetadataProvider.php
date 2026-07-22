<?php declare(strict_types=1);

namespace App\Shared\Infrastructure\EventSourcing;

use App\Shared\Domain\EventSourcing\EventMetadata;
use App\Shared\Domain\EventSourcing\EventMetadataProvider;
use App\User\Domain\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Captures audit provenance from the current HTTP request and security context.
 * A single correlation id is generated once per request and reused for every
 * event produced while handling it, so a whole flow can be traced together.
 * Degrades gracefully to nulls under CLI/worker where there is no request.
 */
final readonly class RequestEventMetadataProvider implements EventMetadataProvider
{
    private const string CORRELATION_ATTRIBUTE = '_es_correlation_id';

    public function __construct(
        private RequestStack $requestStack,
        private Security $security,
    ) {
    }

    public function current(): EventMetadata
    {
        $request = $this->requestStack->getMainRequest();

        $correlationId = null;
        $ip = null;
        if (null !== $request) {
            if (!$request->attributes->has(self::CORRELATION_ATTRIBUTE)) {
                $request->attributes->set(self::CORRELATION_ATTRIBUTE, bin2hex(random_bytes(16)));
            }
            $correlationId = $request->attributes->get(self::CORRELATION_ATTRIBUTE);
            $ip = $request->getClientIp();
        }

        $user = $this->security->getUser();
        $actorId = $user instanceof User ? $user->getId()->toRfc4122() : null;

        return new EventMetadata(correlationId: $correlationId, actorId: $actorId, ip: $ip);
    }
}
