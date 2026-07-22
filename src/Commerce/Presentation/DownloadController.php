<?php declare(strict_types=1);

namespace App\Commerce\Presentation;

use App\Commerce\Infrastructure\Entitlements;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\Storage\FileStorage;
use App\User\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Serves the purchased eBook file. Access requires a logged-in buyer WITH an
 * entitlement for the eBook — no entitlement is an indistinguishable 404, so the
 * endpoint never reveals which eBooks exist. The file is streamed from storage,
 * so its underlying path/disk is never exposed to the client.
 */
#[IsGranted('ROLE_USER')]
final class DownloadController extends AbstractController
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly EbookRepository $ebooks,
        private readonly FileStorage $storage,
    ) {
    }

    #[Route('/pobierz/{ebookId}', name: 'app_download', methods: ['GET'])]
    public function __invoke(string $ebookId): Response
    {
        if (!Uuid::isValid($ebookId)) {
            throw $this->createNotFoundException();
        }
        $ebookUuid = Uuid::fromString($ebookId);

        /** @var User $user */
        $user = $this->getUser();
        if (!$this->entitlements->owns($user->getId(), $ebookUuid)) {
            throw $this->createNotFoundException();
        }

        $ebook = $this->ebooks->get($ebookUuid);
        $file = $ebook?->primaryFile();
        if (null === $file) {
            throw $this->createNotFoundException();
        }

        $media = $file->getMedia();
        if (!$this->storage->fileExists($media->getPath())) {
            throw $this->createNotFoundException();
        }

        $stream = $this->storage->readStream($media->getPath());

        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
        });
        $response->headers->set('Content-Type', $media->getMimeType());
        $response->headers->set('Content-Length', (string) $media->getSize());
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $media->getOriginalName(),
        ));

        return $response;
    }
}
