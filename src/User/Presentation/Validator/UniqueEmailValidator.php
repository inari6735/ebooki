<?php declare(strict_types=1);

namespace App\User\Presentation\Validator;

use App\User\Domain\UserRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueEmailValidator extends ConstraintValidator
{
    public function __construct(
        private readonly UserRepository $users,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueEmail) {
            throw new UnexpectedTypeException($constraint, UniqueEmail::class);
        }

        if (null === $value || '' === $value || !\is_string($value)) {
            return;
        }

        if ($this->users->emailExists($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
