<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final readonly class JwtCookieSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private AccessTokenMinter $accessTokens,
        private RefreshTokenRotator $refreshTokens,
        private TokenCookieFactory $cookies,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        /** @var User $user */
        $user = $token->getUser();

        $jwt = $this->accessTokens->mintFor($user);
        $refreshToken = $this->refreshTokens->issueFor($user);

        // AJAX (e.g. the in-page auth modal): answer with JSON so the caller stays
        // on the page and drives what happens next; the cookies still get set.
        $response = $this->wantsJson($request)
            ? new JsonResponse(['ok' => true])
            : new RedirectResponse($this->safeTargetPath($request));

        $response->headers->setCookie($this->cookies->authCookie($jwt));
        $response->headers->setCookie($this->cookies->refreshCookie($refreshToken->getRefreshToken()));

        return $response;
    }

    public function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');
    }

    private function safeTargetPath(Request $request): string
    {
        $targetPath = (string) $request->request->get('_target_path', '');
        // Only relative paths: never redirect off-site. Backslashes are rejected
        // because browsers normalise them to `/` when resolving a Location header
        // for http(s), so `/\evil.com` would otherwise bypass the `//` guard (CWE-601).
        if ('' === $targetPath
            || !str_starts_with($targetPath, '/')
            || str_starts_with($targetPath, '//')
            || str_contains($targetPath, '\\')) {
            return '/';
        }

        return $targetPath;
    }
}
