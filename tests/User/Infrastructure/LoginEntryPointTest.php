<?php declare(strict_types=1);

namespace App\Tests\User\Infrastructure;

use App\User\Infrastructure\Security\LoginEntryPoint;
use App\User\Infrastructure\Security\TokenCookieFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LoginEntryPointTest extends TestCase
{
    public function testRedirectsToLoginPreservingTargetPath(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')
            ->with('app_login', ['_target_path' => '/courses/new'])
            ->willReturn('/login?_target_path=%2Fcourses%2Fnew');

        $entryPoint = new LoginEntryPoint($urlGenerator, new TokenCookieFactory());
        $response = $entryPoint->start(Request::create('https://localhost/courses/new'));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/login?_target_path=%2Fcourses%2Fnew', $response->getTargetUrl());
    }
}
