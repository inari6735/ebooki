<?php declare(strict_types=1);

namespace App\Ebook\Presentation;

use App\Ebook\Application\PublishEbookFromWizard;
use App\Ebook\Presentation\Form\DetailsStepType;
use App\Ebook\Presentation\Form\PricingStepType;
use App\Ebook\Presentation\PublishEbook\EbookUploadRules;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use App\User\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Session-backed multi-step "Wystaw swojego eBooka" wizard (4 steps). Files are
 * staged as they are added (see {@see EbookStagingController}); the wizard carries
 * only references. At step 4 the whole thing is committed — Ebook + files persisted
 * and moved to permanent storage — for both "Opublikuj" (published) and "Zapisz
 * szkic" (draft).
 */
final class PublishEbookController extends AbstractController
{
    public const string SESSION_KEY = 'publish_ebook_wizard';
    private const string PENDING_KEY = 'publish_ebook_pending';
    private const int LAST_STEP = 4;

    #[Route('/wystaw-ebook/{step}', name: 'app_publish_ebook', requirements: ['step' => '[1-4]'], defaults: ['step' => 1], methods: ['GET', 'POST'])]
    public function __invoke(int $step, Request $request, PublishEbookFromWizard $publisher): Response
    {
        $session = $request->getSession();
        /** @var PublishEbookData $data */
        $data = $session->get(self::SESSION_KEY) ?? new PublishEbookData();

        // Step 4 — finalize: commit the eBook and its files (publish or draft).
        if (self::LAST_STEP === $step) {
            // Resume after login: the user hit finalize while anonymous, signed in,
            // and was sent back here — complete it automatically now.
            if ($request->isMethod('GET') && null !== $this->getUser() && $session->has(self::PENDING_KEY) && $data->hasFiles()) {
                return $this->finalize($publisher, $session, $data, 'draft' === $session->get(self::PENDING_KEY));
            }

            if ($request->isMethod('POST')) {
                if (!$data->hasFiles()) {
                    $this->addFlash('error', 'Dodaj przynajmniej jeden plik eBooka.');

                    return $this->redirectToRoute('app_publish_ebook', ['step' => 1]);
                }

                $asDraft = $request->query->getBoolean('draft');

                // Friendly gate: anonymous authors keep everything they filled in.
                // We only ask them to sign in / sign up at the very last click, then
                // send them straight back here to finish.
                if (null === $this->getUser()) {
                    $session->set(self::PENDING_KEY, $asDraft ? 'draft' : 'publish');
                    $this->addFlash('info', 'Jeszcze chwila! Zaloguj się lub załóż konto, aby dokończyć.');

                    return $this->redirectToRoute('app_login', [
                        '_target_path' => $this->generateUrl('app_publish_ebook', ['step' => self::LAST_STEP]),
                    ]);
                }

                return $this->finalize($publisher, $session, $data, $asDraft);
            }

            return $this->render('ebook/publish/step4.html.twig', ['data' => $data, 'step' => $step]);
        }

        // "Zapisz szkic" on steps 1–3 — save progress in the session and stay.
        if ($request->query->getBoolean('draft')) {
            $session->set(self::SESSION_KEY, $data);
            $this->addFlash('success', 'Szkic został zapisany.');

            return $this->redirectToRoute('app_publish_ebook', ['step' => $step]);
        }

        // Step 1 — files are handled by the async uploader; "Dalej" just gates on
        // at least one staged file before advancing.
        if (1 === $step) {
            if ($request->isMethod('POST')) {
                if (!$data->hasFiles()) {
                    $this->addFlash('error', 'Dodaj przynajmniej jeden plik eBooka, aby przejść dalej.');

                    return $this->redirectToRoute('app_publish_ebook', ['step' => 1]);
                }

                return $this->redirectToRoute('app_publish_ebook', ['step' => 2]);
            }

            return $this->render('ebook/publish/step1.html.twig', [
                'data' => $data,
                'step' => 1,
                'rules' => EbookUploadRules::templateVars(),
            ]);
        }

        if (3 === $step && null === $data->price) {
            $data->price = 29.99; // sensible default so the calculator starts populated
        }

        $form = $this->createForm(
            match ($step) {
                2 => DetailsStepType::class,
                3 => PricingStepType::class,
            },
            $data,
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (2 === $step) {
                // Detailed info: parallel detailKeys[]/detailValues[] → list of {key, value}.
                $keys = $request->request->all('detailKeys');
                $values = $request->request->all('detailValues');
                $details = [];
                foreach ($keys as $i => $k) {
                    $k = trim((string) $k);
                    $v = trim((string) ($values[$i] ?? ''));
                    if ('' !== $k && '' !== $v) {
                        $details[] = ['key' => $k, 'value' => $v];
                    }
                }
                $data->details = $details;
            }

            $session->set(self::SESSION_KEY, $data);

            return $this->redirectToRoute('app_publish_ebook', ['step' => $step + 1]);
        }

        return $this->render("ebook/publish/step{$step}.html.twig", [
            'form' => $form->createView(),
            'data' => $data,
            'step' => $step,
            'rules' => EbookUploadRules::templateVars(),
        ]);
    }

    private function finalize(PublishEbookFromWizard $publisher, SessionInterface $session, PublishEbookData $data, bool $asDraft): Response
    {
        $publisher($data, $this->ownerId(), $asDraft);
        $session->remove(self::SESSION_KEY);
        $session->remove(self::PENDING_KEY);
        $this->addFlash('success', $asDraft
            ? 'Szkic zapisany — pliki zostały wgrane.'
            : 'Opublikowano! Twój eBook i pliki zostały zapisane.');

        return $this->redirectToRoute('app_home');
    }

    private function ownerId(): Uuid
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user->getId();
    }
}
