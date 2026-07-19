<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\User;
use App\User\Domain\UserRepository;
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
    // A real bcrypt hash of 'dummy-password', generated once via:
    // php -r "echo password_hash('dummy-password', PASSWORD_DEFAULT);"
    // Verified against it on the user-not-found path so unknown-email failures
    // burn the same hashing cost as wrong-password failures (no timing side channel).
    private const string DUMMY_HASH = '$2y$12$KC5O.bKDN60CkAFA23cE3urlMKdSRlZVwi.e1T9aILd.THs7K/cpq';

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
                    // Burn the same hashing cost as a real password check to keep
                    // unknown-email and wrong-password failures timing-equivalent.
                    $this->hasherFactory->getPasswordHasher(User::class)
                        ->verify(self::DUMMY_HASH, 'dummy-password');

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
        $request->getSession()->getFlashBag()->add('error', 'Invalid email or password.');

        return new RedirectResponse($this->urlGenerator->generate('app_login'));
    }
}
