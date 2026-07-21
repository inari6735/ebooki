<?php declare(strict_types=1);

namespace App\Ebook\Domain;

/**
 * Language an eBook is written in. Mirrors the choices offered in the publish form.
 */
enum Language: string
{
    case PL = 'pl';
    case EN = 'en';
    case DE = 'de';

    public function label(): string
    {
        return match ($this) {
            self::PL => 'Polski',
            self::EN => 'Angielski',
            self::DE => 'Niemiecki',
        };
    }
}
