<?php declare(strict_types=1);

namespace App\User\Application\Command;

use App\User\Domain\User;
use App\User\Domain\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(RegisterUser $command): void
    {
        $user = new User(Uuid::fromString($command->userId), $command->email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $command->plainPassword));

        $this->users->add($user);
    }
}
