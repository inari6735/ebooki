<?php declare(strict_types=1);

namespace App\Ebook\Presentation;

use App\Ebook\Application\EbookUploadStaging;
use App\Ebook\Domain\EbookFileFormat;
use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\MediaVisibility;
use App\Ebook\Presentation\PublishEbook\EbookUploadRules;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use App\User\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * AJAX endpoints backing the step-1 uploader. Files are staged as they are added
 * (bytes leave the browser here, because a page-reloading wizard cannot hold a
 * 150 MB file across steps); the wizard session keeps only references. Everything
 * is committed to permanent storage at step 4 by {@see PublishEbookController}.
 */
final class EbookStagingController extends AbstractController
{
    public function __construct(
        private readonly EbookUploadStaging $staging,
        private readonly MediaRepository $media,
    ) {
    }

    #[Route('/wystaw-ebook/plik', name: 'app_ebook_stage_file', methods: ['POST'])]
    public function stageFile(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ebook_upload', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Nieprawidłowy token.'], Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'Brak pliku.'], Response::HTTP_BAD_REQUEST);
        }
        if ($error = $this->validate($file, EbookUploadRules::FILE_FORMATS, EbookUploadRules::FILE_MAX_SIZE_MB)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $media = $this->staging->stage($file, MediaVisibility::PRIVATE, $this->ownerId());

        $ref = [
            'mediaId' => $media->getId()->toRfc4122(),
            'name' => $media->getOriginalName(),
            'size' => $this->humanSize($media->getSize()),
            'format' => $media->getExtension(),
        ];

        $data = $this->wizard($request);
        $data->files[] = $ref;
        $this->saveWizard($request, $data);

        return $this->json($ref, Response::HTTP_CREATED);
    }

    #[Route('/wystaw-ebook/okladka', name: 'app_ebook_stage_cover', methods: ['POST'])]
    public function stageCover(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ebook_upload', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Nieprawidłowy token.'], Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => 'Brak pliku.'], Response::HTTP_BAD_REQUEST);
        }
        if ($error = $this->validate($file, EbookUploadRules::COVER_FORMATS, EbookUploadRules::COVER_MAX_SIZE_MB)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $this->wizard($request);

        // Replace any previously staged cover.
        if (null !== $data->coverMediaId && null !== ($old = $this->media->get(Uuid::fromString($data->coverMediaId)))) {
            $this->staging->discard($old);
        }

        $media = $this->staging->stage($file, MediaVisibility::PUBLIC, $this->ownerId());
        $data->coverMediaId = $media->getId()->toRfc4122();
        $data->coverName = $media->getOriginalName();
        $this->saveWizard($request, $data);

        return $this->json([
            'mediaId' => $data->coverMediaId,
            'name' => $data->coverName,
            'previewUrl' => $this->generateUrl('app_ebook_staged_preview', ['mediaId' => $data->coverMediaId]),
        ], Response::HTTP_CREATED);
    }

    #[Route('/wystaw-ebook/plik/{mediaId}/usun', name: 'app_ebook_stage_remove', methods: ['POST'])]
    public function removeStaged(string $mediaId, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ebook_upload', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Nieprawidłowy token.'], Response::HTTP_FORBIDDEN);
        }

        $data = $this->wizard($request);
        $isCover = $data->coverMediaId === $mediaId;
        $inFiles = array_filter($data->files, static fn (array $r): bool => $r['mediaId'] === $mediaId);

        if (!$isCover && [] === $inFiles) {
            return $this->json(['error' => 'Nie znaleziono pliku.'], Response::HTTP_NOT_FOUND);
        }

        if (null !== ($media = $this->media->get(Uuid::fromString($mediaId)))) {
            $this->staging->discard($media);
        }

        if ($isCover) {
            $data->coverMediaId = null;
            $data->coverName = null;
        } else {
            $data->files = array_values(array_filter($data->files, static fn (array $r): bool => $r['mediaId'] !== $mediaId));
        }
        $this->saveWizard($request, $data);

        return $this->json(['ok' => true]);
    }

    #[Route('/wystaw-ebook/podglad-pliku/{mediaId}', name: 'app_ebook_staged_preview', methods: ['GET'])]
    public function preview(string $mediaId, Request $request): Response
    {
        $data = $this->wizard($request);
        $referenced = $data->coverMediaId === $mediaId
            || [] !== array_filter($data->files, static fn (array $r): bool => $r['mediaId'] === $mediaId);

        $media = $this->media->get(Uuid::fromString($mediaId));
        if (!$referenced || null === $media) {
            throw $this->createNotFoundException();
        }

        return new StreamedResponse(function () use ($media): void {
            stream_copy_to_stream($this->staging->readStream($media), fopen('php://output', 'wb'));
        }, Response::HTTP_OK, ['Content-Type' => $media->getMimeType()]);
    }

    /** @param list<string> $formats */
    private function validate(UploadedFile $file, array $formats, int $maxMb): ?string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (!\in_array($ext, $formats, true)) {
            return 'Obsługiwane formaty: '.EbookUploadRules::label($formats).'.';
        }
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            return sprintf('Plik jest za duży (maks. %d MB).', $maxMb);
        }

        return null;
    }

    private function ownerId(): Uuid
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user->getId();
    }

    private function wizard(Request $request): PublishEbookData
    {
        return $request->getSession()->get(PublishEbookController::SESSION_KEY) ?? new PublishEbookData();
    }

    private function saveWizard(Request $request, PublishEbookData $data): void
    {
        $request->getSession()->set(PublishEbookController::SESSION_KEY, $data);
    }

    private function humanSize(int $bytes): string
    {
        $mb = $bytes / 1_048_576;

        return $mb >= 1
            ? number_format($mb, 1, ',', ' ').' MB'
            : number_format($bytes / 1024, 0, ',', ' ').' KB';
    }
}
