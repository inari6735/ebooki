<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final readonly class JwtCookieSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenRotator $refreshTokens,
        private TokenCookieFactory $cookies,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        /** @var User $user */
        $user = $token->getUser();

        $jwt = $this->jwtManager->createFromPayload($user, [
            'sub' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
        ]);
        $refreshToken = $this->refreshTokens->issueFor($user);

        $targetPath = (string) $request->request->get('_target_path', '');
        // Only relative paths: never redirect off-site. Backslashes are rejected
        // because browsers normalise them to `/` when resolving a Location header
        // for http(s), so `/\evil.com` would otherwise bypass the `//` guard (CWE-601).
        if ('' === $targetPath
            || !str_starts_with($targetPath, '/')
            || str_starts_with($targetPath, '//')
            || str_contains($targetPath, '\\')) {
            $targetPath = '/';
        }

        $response = new RedirectResponse($targetPath);
        $response->headers->setCookie($this->cookies->authCookie($jwt));
        $response->headers->setCookie($this->cookies->refreshCookie($refreshToken->getRefreshToken()));

        return $response;
    }
}
