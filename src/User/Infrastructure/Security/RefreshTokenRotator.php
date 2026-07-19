<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\User;
use App\User\Domain\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenInterface;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;

final readonly class RefreshTokenRotator
{
    public const int TTL = 604800; // 7 days, mirrors gesdinet_jwt_refresh_token.ttl

    public function __construct(
        private RefreshTokenGeneratorInterface $generator,
        private RefreshTokenManagerInterface $manager,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function issueFor(User $user): RefreshTokenInterface
    {
        $token = $this->generator->createForUserWithTtl($user, self::TTL);
        $this->manager->save($token);

        return $token;
    }

    public function rotate(RefreshTokenInterface $used): ?RefreshTokenInterface
    {
        $user = $this->users->byEmail((string) $used->getUsername());
        if (null === $user) {
            $this->manager->delete($used);

            return null;
        }

        // Invalidate instead of delete: a later reuse of this token is provable theft.
        // Note: the entity's `valid` column is mapped as Doctrine's `datetime` type,
        // which requires a mutable \DateTime instance (not \DateTimeImmutable).
        $used->setValid(new \DateTime('-1 second'));
        $this->manager->save($used);

        return $this->issueFor($user);
    }

    public function revokeAllFor(string $username): void
    {
        $this->entityManager->createQuery(
            sprintf('DELETE FROM %s rt WHERE rt.username = :username', RefreshToken::class),
        )->execute(['username' => $username]);
    }
}
