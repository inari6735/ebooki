<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\User;
use App\User\Domain\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

final class FormLoginAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly JwtCookieSuccessHandler $successHandler,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UserRepository $users,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->isMethod('POST') && '/login' === $request->getPathInfo();
    }

    public function authenticate(Request $request): Passport
    {
        $email = (string) $request->request->get('email', '');
        $password = (string) $request->request->get('password', '');
        $csrfToken = (string) $request->request->get('_csrf_token', '');

        return new Passport(
            new UserBadge($email, function (string $identifier): User {
                $user = $this->users->byEmail($identifier);
                if (null === $user) {
                    // Burn one KDF run at the currently-configured cost so unknown-email
                    // and wrong-password failures stay timing-equivalent.
                    $this->hasherFactory->getPasswordHasher(User::class)->hash('dummy-password');

                    throw new UserNotFoundException();
                }

                return $user;
            }),
            new PasswordCredentials($password),
            [new CsrfTokenBadge('authenticate', $csrfToken)],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return $this->successHandler->onAuthenticationSuccess($request, $token);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // One generic message — never reveal whether email or password was wrong.
        if ($this->successHandler->wantsJson($request)) {
            return new JsonResponse(['error' => 'Nieprawidłowy e-mail lub hasło.'], Response::HTTP_UNAUTHORIZED);
        }

        $request->getSession()->getFlashBag()->add('error', 'Invalid email or password.');

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
