<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\UserRepository;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Runs BEFORE the firewall (priority 16 > security's 8): when the access JWT is
 * missing/expired but a valid refresh token is present, rotate in-flight so the
 * request proceeds authenticated and the user never sees a redirect.
 */
final class SilentRefreshListener
{
    private const string PENDING_COOKIES = '_silent_refresh_cookies';

    public function __construct(
        private readonly JWTEncoderInterface $jwtEncoder,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly RefreshTokenManagerInterface $refreshTokenManager,
        private readonly RefreshTokenRotator $rotator,
        private readonly TokenCookieFactory $cookies,
        private readonly UserRepository $users,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($this->hasUsableAccessToken($request)) {
            return;
        }

        $rawRefresh = (string) $request->cookies->get(TokenCookieFactory::REFRESH_COOKIE, '');
        if ('' === $rawRefresh) {
            return; // anonymous — the entry point handles protected routes
        }

        $stored = $this->refreshTokenManager->get($rawRefresh);
        if (null === $stored) {
            $this->schedule($request, [$this->cookies->expiredAuthCookie(), $this->cookies->expiredRefreshCookie()]);

            return;
        }

        if (!$stored->isValid()) {
            // A rotated (single-use) token came back: treat as theft.
            $this->rotator->revokeAllFor((string) $stored->getUsername());
            $this->schedule($request, [$this->cookies->expiredAuthCookie(), $this->cookies->expiredRefreshCookie()]);

            return;
        }

        // gesdinet's RefreshTokenInterface (v2.0.0) does not expose getUser(), so the
        // user is loaded up front via the stored username — needed both for the JWT
        // payload and to pass into rotate() consistently with RefreshTokenRotator's own
        // lookup.
        $username = (string) $stored->getUsername();
        $user = $this->users->byEmail($username);
        if (null === $user) {
            $this->schedule($request, [$this->cookies->expiredAuthCookie(), $this->cookies->expiredRefreshCookie()]);

            return;
        }

        $newRefresh = $this->rotator->rotate($stored);
        if (null === $newRefresh) {
            $this->schedule($request, [$this->cookies->expiredAuthCookie(), $this->cookies->expiredRefreshCookie()]);

            return;
        }

        $jwt = $this->jwtManager->createFromPayload($user, [
            'sub' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
        ]);

        // Let the firewall (which runs next) authenticate this very request.
        $request->cookies->set(TokenCookieFactory::AUTH_COOKIE, $jwt);
        $this->schedule($request, [
            $this->cookies->authCookie($jwt),
            $this->cookies->refreshCookie($newRefresh->getRefreshToken()),
        ]);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        /** @var list<Cookie> $pending */
        $pending = $event->getRequest()->attributes->get(self::PENDING_COOKIES, []);
        foreach ($pending as $cookie) {
            $event->getResponse()->headers->setCookie($cookie);
        }
    }

    private function hasUsableAccessToken(Request $request): bool
    {
        $jwt = (string) $request->cookies->get(TokenCookieFactory::AUTH_COOKIE, '');
        if ('' === $jwt) {
            return false;
        }

        try {
            $this->jwtEncoder->decode($jwt);

            return true;
        } catch (JWTDecodeFailureException) {
            return false; // expired or invalid — try the refresh path
        }
    }

    /** @param list<Cookie> $cookies */
    private function schedule(Request $request, array $cookies): void
    {
        $request->attributes->set(self::PENDING_COOKIES, $cookies);
    }
}
