<?php declare(strict_types=1);

namespace App\Ebook\Presentation;

use App\Ebook\Application\EbookUploadStaging;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\MediaRepository;
use App\Ebook\Domain\MediaStatus;
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
    /** Hard cap per chunk request (must exceed the client's 8 MB chunk + multipart overhead). */
    private const int CHUNK_MAX_BYTES = 12 * 1024 * 1024;
    /** Ceiling on a single upload's part count (200 MB / 8 MB ≈ 25; leave slack). */
    private const int MAX_CHUNK_INDEX = 512;

    public function __construct(
        private readonly EbookUploadStaging $staging,
        private readonly MediaRepository $media,
        private readonly EbookRepository $ebooks,
    ) {
    }

    /**
     * Begin a chunked eBook-file upload. The whole file is validated up-front from
     * its declared name/size (cheap, early rejection) and a time-ordered upload id
     * is minted; the browser then streams the bytes in small chunks so PHP never
     * has to accept one huge request. The checksum-duplicate check can only run
     * once the bytes are here, so it lives in {@see finalizeChunkedFile}.
     */
    #[Route('/wystaw-ebook/plik/init', name: 'app_ebook_chunk_init', methods: ['POST'])]
    public function initChunkedFile(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ebook_upload', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Nieprawidłowy token.'], Response::HTTP_FORBIDDEN);
        }

        $name = (string) $request->request->get('name');
        $size = $request->request->getInt('size');

        if ($error = $this->validateFileMeta($this->stagingData($request), $name, $size)) {
            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['uploadId' => Uuid::v7()->toRfc4122()], Response::HTTP_CREATED);
    }

    /** Receive one chunk of an in-progress upload (idempotent by index). */
    #[Route('/wystaw-ebook/plik/chunk', name: 'app_ebook_chunk', methods: ['POST'])]
    public function stageChunk(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ebook_upload', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Nieprawidłowy token.'], Response::HTTP_FORBIDDEN);
        }

        $uploadId = (string) $request->request->get('uploadId');
        $index = $request->request->getInt('index');
        $chunk = $request->files->get('chunk');

        if (!Uuid::isValid($uploadId) || $index < 0 || $index > self::MAX_CHUNK_INDEX) {
            return $this->json(['error' => 'Nieprawidłowe żądanie.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$chunk instanceof UploadedFile || \UPLOAD_ERR_OK !== $chunk->getError()) {
            return $this->json(['error' => 'Nie udało się wysłać fragmentu — spróbuj ponownie.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($chunk->getSize() > self::CHUNK_MAX_BYTES) {
            return $this->json(['error' => 'Fragment jest zbyt duży.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->staging->putChunk($uploadId, $index, $chunk);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Assemble the uploaded chunks into a staged file. Re-validates everything
     * authoritatively (format/count/duplicate-format up front, then completeness +
     * size while assembling, then the checksum duplicate) — a failure never leaves
     * a usable Media, and rejected/incomplete chunks are cleaned up.
     */
    #[Route('/wystaw-ebook/plik/finalize', name: 'app_ebook_chunk_finalize', methods: ['POST'])]
    public function finalizeChunkedFile(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ebook_upload', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Nieprawidłowy token.'], Response::HTTP_FORBIDDEN);
        }

        $uploadId = (string) $request->request->get('uploadId');
        $total = $request->request->getInt('total');
        $name = (string) $request->request->get('name');
        $size = $request->request->getInt('size');
        $mime = (string) ($request->request->get('mime') ?: 'application/octet-stream');

        if (!Uuid::isValid($uploadId) || $total < 1) {
            return $this->json(['error' => 'Nieprawidłowe żądanie.'], Response::HTTP_BAD_REQUEST);
        }

        $data = $this->stagingData($request);
        if ($error = $this->validateFileMeta($data, $name, $size)) {
            $this->staging->abortChunks($uploadId);

            return $this->json(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $media = $this->staging->assembleChunks($uploadId, $total, $name, $mime, $size, MediaVisibility::PRIVATE, $this->ownerId());
        } catch (\RuntimeException $e) {
            // Incomplete/mismatched upload: leave the parts for a retry or the GC.
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        foreach ($data->files as $existing) {
            if ($existing['checksum'] === $media->getChecksum()) {
                $this->staging->discard($media);

                return $this->json(['error' => 'Ten plik został już dodany.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $ref = [
            'mediaId' => $media->getId()->toRfc4122(),
            'name' => $media->getOriginalName(),
            'size' => $this->humanSize($media->getSize()),
            'format' => $media->getExtension(),
            'checksum' => $media->getChecksum(),
        ];

        $data->files[] = $ref;
        $this->saveStagingData($request, $data);

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

        $data = $this->stagingData($request);

        // Replace the previous cover. A committed (ready) cover — when editing an
        // existing eBook — is NOT deleted here (the edit might be cancelled); only a
        // still-staged (pending) cover is discarded. Committed removals happen on save.
        $this->discardIfPending($data->coverMediaId);

        $media = $this->staging->stage($file, MediaVisibility::PUBLIC, $this->ownerId());
        $data->coverMediaId = $media->getId()->toRfc4122();
        $data->coverName = $media->getOriginalName();
        $this->saveStagingData($request, $data);

        return $this->json([
            'mediaId' => $data->coverMediaId,
            'name' => $data->coverName,
            'previewUrl' => $this->previewUrl($request, $data->coverMediaId),
        ], Response::HTTP_CREATED);
    }

    #[Route('/wystaw-ebook/plik/{mediaId}/usun', name: 'app_ebook_stage_remove', methods: ['POST'])]
    public function removeStaged(string $mediaId, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('ebook_upload', (string) $request->request->get('_token'))) {
            return $this->json(['error' => 'Nieprawidłowy token.'], Response::HTTP_FORBIDDEN);
        }

        $data = $this->stagingData($request);
        $isCover = $data->coverMediaId === $mediaId;
        $inFiles = array_filter($data->files, static fn (array $r): bool => $r['mediaId'] === $mediaId);

        if (!$isCover && [] === $inFiles) {
            return $this->json(['error' => 'Nie znaleziono pliku.'], Response::HTTP_NOT_FOUND);
        }

        // Only discard still-staged blobs; committed files removed while editing are
        // deleted on save (so a cancelled edit keeps the eBook intact).
        $this->discardIfPending($mediaId);

        if ($isCover) {
            $data->coverMediaId = null;
            $data->coverName = null;
        } else {
            $data->files = array_values(array_filter($data->files, static fn (array $r): bool => $r['mediaId'] !== $mediaId));
        }
        $this->saveStagingData($request, $data);

        return $this->json(['ok' => true]);
    }

    #[Route('/wystaw-ebook/podglad-pliku/{mediaId}', name: 'app_ebook_staged_preview', methods: ['GET'])]
    public function preview(string $mediaId, Request $request): Response
    {
        $data = $this->stagingData($request);
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

    /**
     * Validate an eBook file from its declared name + byte size alone (format,
     * per-file size cap, count, one-file-per-format) — everything checkable before
     * the bytes arrive. Shared by chunk init (early rejection) and finalize
     * (authoritative). Returns a Polish error message, or null when acceptable.
     */
    private function validateFileMeta(PublishEbookData $data, string $name, int $size): ?string
    {
        $ext = strtolower(pathinfo($name, \PATHINFO_EXTENSION));
        if ('' === $name || !\in_array($ext, EbookUploadRules::FILE_FORMATS, true)) {
            return sprintf('Nieobsługiwany format „.%s". Dozwolone: %s.', $ext, EbookUploadRules::label(EbookUploadRules::FILE_FORMATS));
        }
        if ($size < 1) {
            return 'Nie udało się odczytać pliku — spróbuj ponownie.';
        }
        if ($size > EbookUploadRules::FILE_MAX_SIZE_MB * 1024 * 1024) {
            return sprintf('Plik „%s" jest za duży (maks. %d MB).', $name, EbookUploadRules::FILE_MAX_SIZE_MB);
        }
        if (\count($data->files) >= EbookUploadRules::fileMaxCount()) {
            return sprintf('Możesz dodać maksymalnie %d plików.', EbookUploadRules::fileMaxCount());
        }
        foreach ($data->files as $existing) {
            if ($existing['format'] === $ext) {
                return sprintf('Plik w formacie %s został już dodany — każdy format można dodać tylko raz.', strtoupper($ext));
            }
        }

        return null;
    }

    /** @param list<string> $formats */
    private function validate(UploadedFile $file, array $formats, int $maxMb): ?string
    {
        // A failed upload (e.g. exceeded PHP's upload_max_filesize) has no path —
        // report it clearly instead of blowing up further down.
        if (\UPLOAD_ERR_OK !== $file->getError()) {
            return \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? sprintf('Plik jest za duży (maks. %d MB).', $maxMb)
                : 'Nie udało się wgrać pliku — spróbuj ponownie.';
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!\in_array($ext, $formats, true)) {
            return sprintf('Nieobsługiwany format „.%s". Dozwolone: %s.', $ext, EbookUploadRules::label($formats));
        }
        if ($file->getSize() > $maxMb * 1024 * 1024) {
            return sprintf('Plik „%s" jest za duży (maks. %d MB).', $file->getClientOriginalName(), $maxMb);
        }

        return null;
    }

    private function ownerId(): ?Uuid
    {
        // The wizard can be filled anonymously — staged files just have no owner
        // yet. Ownership is set when the (now logged-in) author finalizes.
        $user = $this->getUser();

        return $user instanceof User ? $user->getId() : null;
    }

    private function stagingData(Request $request): PublishEbookData
    {
        return $request->getSession()->get($this->contextKey($request)) ?? new PublishEbookData();
    }

    private function saveStagingData(Request $request, PublishEbookData $data): void
    {
        $request->getSession()->set($this->contextKey($request), $data);
    }

    /**
     * Which staging workspace a request belongs to: the wizard by default, or an
     * edit session (`ctx` = eBook id) — which requires the eBook to belong to the
     * signed-in user, so one author can't touch another's files.
     */
    private function contextKey(Request $request): string
    {
        $ctx = $this->ctx($request);
        if ('' === $ctx) {
            return PublishEbookController::SESSION_KEY;
        }

        $ebook = Uuid::isValid($ctx) ? $this->ebooks->get(Uuid::fromString($ctx)) : null;
        $user = $this->getUser();
        if (null === $ebook || !$user instanceof User || !$ebook->getOwnerId()->equals($user->getId())) {
            throw $this->createAccessDeniedException();
        }

        return ListedEbooksController::EDIT_SESSION_PREFIX.$ctx;
    }

    private function discardIfPending(?string $mediaId): void
    {
        if (null === $mediaId) {
            return;
        }
        $media = $this->media->get(Uuid::fromString($mediaId));
        if (null !== $media && MediaStatus::PENDING === $media->getStatus()) {
            $this->staging->discard($media);
        }
    }

    private function previewUrl(Request $request, string $mediaId): string
    {
        $params = ['mediaId' => $mediaId];
        if ('' !== $ctx = $this->ctx($request)) {
            $params['ctx'] = $ctx;
        }

        return $this->generateUrl('app_ebook_staged_preview', $params);
    }

    /** The staging workspace context (an eBook id, or '' for the wizard), from body or query. */
    private function ctx(Request $request): string
    {
        return (string) ($request->request->get('ctx') ?? $request->query->get('ctx') ?? '');
    }

    private function humanSize(int $bytes): string
    {
        $mb = $bytes / 1_048_576;

        return $mb >= 1
            ? number_format($mb, 1, ',', ' ').' MB'
            : number_format($bytes / 1024, 0, ',', ' ').' KB';
    }
}
