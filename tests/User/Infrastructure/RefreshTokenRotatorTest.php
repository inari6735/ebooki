<?php declare(strict_types=1);

namespace App\Tests\User\Infrastructure;

use App\User\Domain\User;
use App\User\Domain\UserRepository;
use App\User\Infrastructure\Security\RefreshTokenRotator;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RefreshTokenRotatorTest extends KernelTestCase
{
    private RefreshTokenRotator $rotator;
    private RefreshTokenManagerInterface $manager;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rotator = self::getContainer()->get(RefreshTokenRotator::class);
        $this->manager = self::getContainer()->get(RefreshTokenManagerInterface::class);

        $this->user = new User(Uuid::v7(), 'rotate@example.com');
        $this->user->setPassword('hash');
        self::getContainer()->get(UserRepository::class)->add($this->user);
    }

    public function testIssueForCreatesValidToken(): void
    {
        $token = $this->rotator->issueFor($this->user);

        $stored = $this->manager->get($token->getRefreshToken());
        self::assertNotNull($stored);
        self::assertTrue($stored->isValid());
        self::assertSame('rotate@example.com', $stored->getUsername());
    }

    public function testRotateInvalidatesOldAndIssuesNew(): void
    {
        $old = $this->rotator->issueFor($this->user);

        $new = $this->rotator->rotate($old);

        self::assertNotNull($new);
        self::assertNotSame($old->getRefreshToken(), $new->getRefreshToken());

        $oldStored = $this->manager->get($old->getRefreshToken());
        self::assertNotNull($oldStored, 'rotated token must remain findable for theft detection');
        self::assertFalse($oldStored->isValid());
        self::assertTrue($this->manager->get($new->getRefreshToken())->isValid());
    }

    public function testRevokeAllForDeletesEveryToken(): void
    {
        $a = $this->rotator->issueFor($this->user);
        $b = $this->rotator->issueFor($this->user);

        $this->rotator->revokeAllFor('rotate@example.com');

        self::assertNull($this->manager->get($a->getRefreshToken()));
        self::assertNull($this->manager->get($b->getRefreshToken()));
    }
}
