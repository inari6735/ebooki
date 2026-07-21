<?php declare(strict_types=1);

namespace App\Ebook\Presentation;

use App\Ebook\Application\DeleteEbook;
use App\Ebook\Application\ReconcileEbookMedia;
use App\Ebook\Application\UpdateEbook;
use App\Ebook\Domain\Ebook;
use App\Ebook\Domain\EbookRepository;
use App\Ebook\Domain\EbookStatus;
use App\Ebook\Presentation\Form\DetailFields;
use App\Ebook\Presentation\Form\EditEbookType;
use App\Ebook\Presentation\PublishEbook\EbookUploadRules;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use App\User\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * "Wystawione" — the author manages their own eBooks: filter by status, hide/show a
 * published listing, or delete it. Every mutation is CSRF-checked and scoped to the
 * owner (another user's eBook 404s).
 */
final class ListedEbooksController extends AbstractController
{
    /** Session-key prefix for a per-eBook file/cover editing workspace. */
    public const string EDIT_SESSION_PREFIX = 'edit_ebook_';

    private const string CSRF_ID = 'ebook_manage';

    public function __construct(private readonly EbookRepository $ebooks)
    {
    }

    #[Route('/panel/wystawione', name: 'app_dashboard_listed', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $all = $this->ebooks->findByOwner($this->currentUser()->getId());

        $counts = [
            'all' => \count($all),
            'published' => \count($this->only($all, EbookStatus::PUBLISHED)),
            'draft' => \count($this->only($all, EbookStatus::DRAFT)),
            'unpublished' => \count($this->only($all, EbookStatus::UNPUBLISHED)),
        ];

        $filter = $request->query->getString('status', 'all');
        $ebooks = match ($filter) {
            'published' => $this->only($all, EbookStatus::PUBLISHED),
            'draft' => $this->only($all, EbookStatus::DRAFT),
            'unpublished' => $this->only($all, EbookStatus::UNPUBLISHED),
            default => $all,
        };

        return $this->render('dashboard/listed.html.twig', [
            'active' => 'wystawione',
            'ebooks' => array_values($ebooks),
            'counts' => $counts,
            'filter' => \array_key_exists($filter, $counts) ? $filter : 'all',
        ]);
    }

    #[Route('/panel/ebook/{id}/edytuj', name: 'app_ebook_edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, UpdateEbook $update, ReconcileEbookMedia $reconcile): Response
    {
        $ebook = $this->owned($id);
        $session = $request->getSession();
        $editKey = self::EDIT_SESSION_PREFIX.$id;

        // A fresh visit starts the file/cover workspace from the eBook's current
        // state; AJAX add/remove/cover then mutate that workspace (see the shared
        // uploader endpoints, ctx = eBook id) until save reconciles it back.
        if ($request->isMethod('GET')) {
            $session->set($editKey, $this->mediaWorkspace($ebook));
        }
        /** @var PublishEbookData $media */
        $media = $session->get($editKey) ?? $this->mediaWorkspace($ebook);

        $data = $this->toData($ebook);
        // Same form/fields/validation as the wizard (EbookFields + PublishEbookData).
        $form = $this->createForm(EditEbookType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $data->details = DetailFields::collect($request);
            if (null !== ($error = DetailFields::error($data->details))) {
                $this->addFlash('error', $error);
            } elseif (!$media->hasFiles()) {
                $this->addFlash('error', 'eBook musi mieć przynajmniej jeden plik.');
            } elseif ($form->isValid()) {
                $update($ebook, $data);
                $reconcile($ebook, $media->files, $media->coverMediaId);
                $session->remove($editKey);
                $this->addFlash('success', sprintf('Zapisano zmiany w „%s".', $ebook->getTitle()));

                return $this->redirectToRoute('app_dashboard_listed');
            }
        }

        $response = $this->render('dashboard/edit.html.twig', [
            'active' => 'wystawione',
            'ebook' => $ebook,
            'form' => $form->createView(),
            'data' => $data,
            'media' => $media,
            'rules' => EbookUploadRules::templateVars(),
            'ctx' => $id,
        ]);
        // A failed submit re-renders; return 422 so Turbo actually shows it (Turbo
        // ignores a 200 response to a form POST, expecting success to redirect).
        if ($form->isSubmitted()) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $response;
    }

    #[Route('/panel/wystawione/{id}/widocznosc', name: 'app_ebook_toggle_visibility', methods: ['POST'])]
    public function toggleVisibility(string $id, Request $request): Response
    {
        $ebook = $this->owned($id);
        if ($this->csrfInvalid($request)) {
            return $this->back();
        }

        if (EbookStatus::PUBLISHED === $ebook->getStatus()) {
            $ebook->unpublish();
            $this->addFlash('success', sprintf('eBook „%s" został ukryty ze sklepu.', $ebook->getTitle()));
        } else {
            $ebook->publish(new \DateTimeImmutable());
            $this->addFlash('success', sprintf('eBook „%s" został opublikowany.', $ebook->getTitle()));
        }
        $this->ebooks->save($ebook);

        return $this->back();
    }

    #[Route('/panel/wystawione/{id}/usun', name: 'app_ebook_delete', methods: ['POST'])]
    public function delete(string $id, Request $request, DeleteEbook $delete): Response
    {
        $ebook = $this->owned($id);
        if ($this->csrfInvalid($request)) {
            return $this->back();
        }

        $title = $ebook->getTitle();
        $delete($ebook);
        $this->addFlash('success', sprintf('eBook „%s" został usunięty.', $title));

        return $this->back();
    }

    /** @param list<Ebook> $ebooks @return list<Ebook> */
    private function only(array $ebooks, EbookStatus $status): array
    {
        return array_values(array_filter($ebooks, static fn (Ebook $e): bool => $status === $e->getStatus()));
    }

    private function toData(Ebook $e): PublishEbookData
    {
        $data = new PublishEbookData();
        $data->title = $e->getTitle();
        $data->author = $e->getAuthorName();
        $data->category = $e->getCategory()?->getSlug();
        $data->language = $e->getLanguage();
        $data->shortDescription = $e->getShortDescription();
        $data->description = $e->getDescription();
        $data->details = array_map(static fn ($a): array => ['key' => $a->label, 'value' => $a->value], $e->attributes());

        $pricing = $e->pricing();
        $units = $pricing->currency()->minorUnits();
        $data->isFree = $pricing->isFree();
        $data->payWhatYouWant = $pricing->isPayWhatYouWant();
        $data->price = null !== ($m = $pricing->baseAmountMinor()) ? $m / $units : null;
        $data->promoPrice = null !== ($m = $pricing->promoAmountMinor()) ? $m / $units : null;

        return $data;
    }

    /** Snapshot the eBook's current files + cover as an uploader workspace. */
    private function mediaWorkspace(Ebook $ebook): PublishEbookData
    {
        $data = new PublishEbookData();
        foreach ($ebook->files() as $file) {
            $media = $file->getMedia();
            $data->files[] = [
                'mediaId' => $media->getId()->toRfc4122(),
                'name' => $media->getOriginalName(),
                'size' => $this->humanSize($media->getSize()),
                'format' => $file->getFormat()->value,
                'checksum' => (string) $media->getChecksum(),
            ];
        }
        if (null !== $cover = $ebook->getCover()) {
            $data->coverMediaId = $cover->getId()->toRfc4122();
            $data->coverName = $cover->getOriginalName();
        }

        return $data;
    }

    private function humanSize(int $bytes): string
    {
        $mb = $bytes / 1_048_576;

        return $mb >= 1
            ? number_format($mb, 1, ',', ' ').' MB'
            : number_format($bytes / 1024, 0, ',', ' ').' KB';
    }

    private function owned(string $id): Ebook
    {
        $ebook = Uuid::isValid($id) ? $this->ebooks->get(Uuid::fromString($id)) : null;
        if (null === $ebook || !$ebook->getOwnerId()->equals($this->currentUser()->getId())) {
            throw $this->createNotFoundException();
        }

        return $ebook;
    }

    private function csrfInvalid(Request $request): bool
    {
        if ($this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            return false;
        }
        $this->addFlash('error', 'Sesja wygasła — spróbuj ponownie.');

        return true;
    }

    private function back(): Response
    {
        return $this->redirectToRoute('app_dashboard_listed');
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
