<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final readonly class CookieLogout
{
    public function __construct(
        private RefreshTokenRotator $refreshTokens,
        private TokenCookieFactory $cookies,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    #[AsEventListener]
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if (null !== $user) {
            $this->refreshTokens->revokeAllFor($user->getUserIdentifier());
        }

        $response = new RedirectResponse($this->urlGenerator->generate('app_login'));
        $response->headers->setCookie($this->cookies->expiredAuthCookie());
        $response->headers->setCookie($this->cookies->expiredRefreshCookie());

        $event->setResponse($response);
    }
}
