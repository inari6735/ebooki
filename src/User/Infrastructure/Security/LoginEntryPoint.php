<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final readonly class LoginEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TokenCookieFactory $cookies,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $response = new RedirectResponse(
            $this->urlGenerator->generate('app_login', ['_target_path' => $request->getRequestUri()]),
        );
        $response->headers->setCookie($this->cookies->expiredAuthCookie());
        $response->headers->setCookie($this->cookies->expiredRefreshCookie());

        return $response;
    }
}
