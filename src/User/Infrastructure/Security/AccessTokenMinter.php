<?php declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

final readonly class AccessTokenMinter
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
    ) {
    }

    public function mintFor(User $user): string
    {
        return $this->jwtManager->createFromPayload($user, [
            'sub' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
        ]);
    }
}
