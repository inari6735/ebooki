<?php declare(strict_types=1);

namespace App\User\Presentation\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class UniqueEmail extends Constraint
{
    public string $message = 'This email is already registered.';
}
