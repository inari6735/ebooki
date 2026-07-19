<?php declare(strict_types=1);

namespace App\Tests\User\Infrastructure;

use App\User\Domain\User;
use App\User\Domain\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DoctrineUserRepositoryTest extends KernelTestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(UserRepository::class);
    }

    public function testAddAndFindByEmail(): void
    {
        $user = new User(Uuid::v7(), 'stored@example.com');
        $user->setPassword('hash');

        $this->repository->add($user);

        $found = $this->repository->byEmail('stored@example.com');
        self::assertNotNull($found);
        self::assertTrue($user->getId()->equals($found->getId()));
    }

    public function testByEmailReturnsNullForUnknown(): void
    {
        self::assertNull($this->repository->byEmail('nobody@example.com'));
    }

    public function testEmailExists(): void
    {
        $user = new User(Uuid::v7(), 'taken@example.com');
        $user->setPassword('hash');
        $this->repository->add($user);

        self::assertTrue($this->repository->emailExists('taken@example.com'));
        self::assertFalse($this->repository->emailExists('free@example.com'));
    }
}
