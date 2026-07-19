<?php declare(strict_types=1);

namespace App\Tests\User\Application;

use App\Tests\User\Doubles\FakePasswordHasher;
use App\Tests\User\Doubles\InMemoryUserRepository;
use App\User\Application\Command\RegisterUser;
use App\User\Application\Command\RegisterUserHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RegisterUserHandlerTest extends TestCase
{
    public function testRegistersUserWithHashedPassword(): void
    {
        $repository = new InMemoryUserRepository();
        $handler = new RegisterUserHandler($repository, new FakePasswordHasher());
        $userId = Uuid::v7()->toRfc4122();

        $handler(new RegisterUser($userId, 'new@example.com', 's3cretpass'));

        $user = $repository->byEmail('new@example.com');
        self::assertNotNull($user);
        self::assertSame($userId, $user->getId()->toRfc4122());
        self::assertSame('hashed:s3cretpass', $user->getPassword());
        self::assertSame(['ROLE_USER'], $user->getRoles());
    }
}
