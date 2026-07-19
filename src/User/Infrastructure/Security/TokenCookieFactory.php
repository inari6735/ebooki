<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Symfony\Component\HttpFoundation\Cookie;

final readonly class TokenCookieFactory
{
    public const string AUTH_COOKIE = 'AUTH_TOKEN';
    public const string REFRESH_COOKIE = 'REFRESH_TOKEN';

    private const int AUTH_TTL = 900;      // mirrors lexik token_ttl
    private const int REFRESH_TTL = RefreshTokenRotator::TTL;

    public function authCookie(string $jwt): Cookie
    {
        return $this->cookie(self::AUTH_COOKIE, $jwt, time() + self::AUTH_TTL);
    }

    public function refreshCookie(string $token): Cookie
    {
        return $this->cookie(self::REFRESH_COOKIE, $token, time() + self::REFRESH_TTL);
    }

    public function expiredAuthCookie(): Cookie
    {
        return $this->cookie(self::AUTH_COOKIE, '', 1);
    }

    public function expiredRefreshCookie(): Cookie
    {
        return $this->cookie(self::REFRESH_COOKIE, '', 1);
    }

    private function cookie(string $name, string $value, int $expire): Cookie
    {
        return Cookie::create(
            $name,
            $value,
            $expire,
            '/',
            null,
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
