<?php declare(strict_types=1);

namespace App\Tests\User\Domain;

use App\User\Domain\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class UserTest extends TestCase
{
    public function testIdentifierIsEmail(): void
    {
        $user = new User(Uuid::v7(), 'reader@example.com');

        self::assertSame('reader@example.com', $user->getUserIdentifier());
        self::assertSame('reader@example.com', $user->getEmail());
    }

    public function testEveryUserHasRoleUser(): void
    {
        $user = new User(Uuid::v7(), 'reader@example.com');

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testPasswordHashIsStored(): void
    {
        $user = new User(Uuid::v7(), 'reader@example.com');
        $user->setPassword('$2y$04$hash');

        self::assertSame('$2y$04$hash', $user->getPassword());
    }
}
