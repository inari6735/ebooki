<?php declare(strict_types=1);

namespace App\Ebook\Application;

use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\Thumbnail\CoverThumbnailProfile;
use App\Ebook\Domain\Thumbnail\ThumbnailGenerator;
use App\Ebook\Infrastructure\MediaThumbnailRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Generates the cover thumbnails for one media and records them in the
 * media_thumbnails projection.
 *
 * It reads Media ONLY to learn the source storage key — it NEVER modifies Media
 * or its status. Thumbnails are entirely decoupled: their lifecycle lives in
 * media_thumbnails alone. Idempotent: the projection is replaced wholesale, so
 * redeliveries and regenerations converge. A missing media is a no-op (the media
 * was deleted meanwhile), never a failure.
 */
#[AsMessageHandler(bus: 'messenger.bus.command')]
final readonly class GenerateCoverThumbnailsHandler
{
    public function __construct(
        private MediaRepository $media,
        private ThumbnailGenerator $generator,
        private MediaThumbnailRepository $thumbnails,
    ) {
    }

    public function __invoke(GenerateCoverThumbnails $message): void
    {
        $media = $this->media->get(Uuid::fromString($message->mediaId));
        if (null === $media) {
            return;
        }

        $generated = $this->generator->generate(
            $media->getPath(),
            CoverThumbnailProfile::specs(),
            CoverThumbnailProfile::FORMAT,
            CoverThumbnailProfile::QUALITY,
        );

        $this->thumbnails->replaceForMedia($media->getId(), CoverThumbnailProfile::FORMAT, $generated);
    }
}
