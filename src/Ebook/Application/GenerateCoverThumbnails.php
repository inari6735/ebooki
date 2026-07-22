<?php declare(strict_types=1);

namespace App\Ebook\Application;

use App\Shared\Application\Bus\Command;
use App\Shared\Application\Transport\AsyncTransport;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Dispatched after a cover blob is committed. Handled ASYNCHRONOUSLY on the
 * worker so the upload request returns immediately; the resizing happens
 * off-request with Messenger retries. Carries only the cover's media id — the
 * one and only link between an ebook cover and its thumbnails.
 */
final readonly class GenerateCoverThumbnails implements Command, AsyncTransport
{
    public function __construct(
        #[Assert\Uuid]
        public string $mediaId,
    ) {
    }
}
