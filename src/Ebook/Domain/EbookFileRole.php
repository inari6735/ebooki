<?php declare(strict_types=1);

namespace App\Ebook\Domain;

/**
 * What purpose a file serves for its eBook: the complete paid file, or a free
 * preview fragment. Lets one table hold both the product and its teaser.
 */
enum EbookFileRole: string
{
    case FULL = 'full';
    case SAMPLE = 'sample';
}
