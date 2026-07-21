<?php declare(strict_types=1);

namespace App\Ebook\Domain;

/**
 * Processing state of an uploaded file. `PENDING` while async work (virus scan,
 * format conversion, thumbnailing) runs; `READY` once it can be served.
 */
enum MediaStatus: string
{
    case PENDING = 'pending';
    case READY = 'ready';
    case FAILED = 'failed';
}
