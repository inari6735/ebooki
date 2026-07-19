<?php declare(strict_types=1);

namespace App\Tests\User\Doubles;

use App\User\Domain\User;
use App\User\Domain\UserRepository;

final class InMemoryUserRepository implements UserRepository
{
    /** @var array<string, User> keyed by email */
    public array $users = [];

    public function byEmail(string $email): ?User
    {
        return $this->users[$email] ?? null;
    }

    public function add(User $user): void
    {
        $this->users[$user->getEmail()] = $user;
    }

    public function emailExists(string $email): bool
    {
        return isset($this->users[$email]);
    }
}
