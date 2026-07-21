<?php declare(strict_types=1);

namespace App\Ebook\Domain;

/**
 * Whether a stored file is publicly reachable (covers, free samples) or has to
 * be served behind an access check (paid eBook files).
 */
enum MediaVisibility: string
{
    case PUBLIC = 'public';
    case PRIVATE = 'private';
}
