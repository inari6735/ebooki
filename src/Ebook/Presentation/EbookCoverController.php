<?php declare(strict_types=1);

namespace App\Ebook\Presentation;

use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\EbookStatus;
use App\Ebook\Domain\Storage\FileStorage;
use App\Ebook\Infrastructure\MediaThumbnailRepository;
use App\User\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Serves an eBook's cover image, streamed from storage (the storage path is never
 * exposed). Covers of PUBLISHED eBooks are public and cacheable; an unpublished
 * eBook's cover is visible only to its owner (a preview) — never a 404 that leaks
 * whether the eBook exists otherwise.
 */
final class EbookCoverController extends AbstractController
{
    public function __construct(
        private readonly EbookRepository $ebooks,
        private readonly FileStorage $storage,
        private readonly MediaThumbnailRepository $thumbnails,
    ) {
    }

    #[Route('/ebook/okladka/{id}', name: 'app_ebook_cover', methods: ['GET'])]
    public function __invoke(string $id, Request $request): Response
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        $ebook = $this->ebooks->get(Uuid::fromString($id));
        if (null === $ebook || !$this->isVisible($ebook)) {
            throw $this->createNotFoundException();
        }

        $cover = $ebook->getCover();
        if (null === $cover) {
            throw $this->createNotFoundException();
        }

        $width = $request->query->getInt('w');
        if ($width > 0) {
            // Exact-size request: serve THAT thumbnail or nothing. Never fall back
            // to the original or another width — that would answer a different
            // question than the one asked.
            $variant = $this->thumbnails->variant($cover->getId(), $width);
            if (null === $variant) {
                throw $this->createNotFoundException();
            }
            $key = $variant['key'];
            $contentType = 'image/'.$variant['format'];
            $length = $variant['size'];
            $longCache = true;
        } else {
            // No size asked → the original cover (e.g. the detail page).
            $key = $cover->getPath();
            $contentType = $cover->getMimeType();
            $length = $cover->getSize();
            $longCache = false;
        }

        if (!$this->storage->fileExists($key)) {
            throw $this->createNotFoundException();
        }

        $stream = $this->storage->readStream($key);

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
        });
        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Content-Length', (string) $length);

        if (EbookStatus::PUBLISHED === $ebook->getStatus()) {
            $response->setPublic();
            // Thumbnails are addressed by width and rarely change → cache hard.
            $response->setMaxAge($longCache ? 31_536_000 : 3600);
        } else {
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
        }

        return $response;
    }

    private function isVisible(Ebook $ebook): bool
    {
        if (EbookStatus::PUBLISHED === $ebook->getStatus()) {
            return true;
        }
        $user = $this->getUser();

        return $user instanceof User && $ebook->getOwnerId()->equals($user->getId());
    }
}
