<?php declare(strict_types=1);

namespace App\User\Application\Command;

use App\Shared\Application\Bus\Command;
use App\Shared\Application\Transport\SyncTransport;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RegisterUser implements Command, SyncTransport
{
    public function __construct(
        #[Assert\Uuid]
        public string $userId,
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        public string $plainPassword,
    ) {
    }
}
