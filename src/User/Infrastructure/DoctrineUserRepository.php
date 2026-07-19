<?php declare(strict_types=1);

namespace App\User\Infrastructure;

use App\User\Domain\User;
use App\User\Domain\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function byEmail(string $email): ?User
    {
        return $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $email]);
    }

    public function add(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    public function emailExists(string $email): bool
    {
        return null !== $this->byEmail($email);
    }
}
