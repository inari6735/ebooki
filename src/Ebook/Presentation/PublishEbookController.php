<?php declare(strict_types=1);

namespace App\Ebook\Presentation;

use App\Ebook\Presentation\Form\DetailsStepType;
use App\Ebook\Presentation\Form\FileStepType;
use App\Ebook\Presentation\Form\PricingStepType;
use App\Ebook\Presentation\PublishEbook\PublishEbookData;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Session-backed multi-step "Wystaw swojego eBooka" wizard (4 steps).
 * Backend persistence is intentionally not implemented yet — the final
 * "Opublikuj" action is a no-op that clears the wizard and flashes success.
 */
final class PublishEbookController extends AbstractController
{
    private const string SESSION_KEY = 'publish_ebook_wizard';
    private const int LAST_STEP = 4;

    #[Route('/wystaw-ebook/{step}', name: 'app_publish_ebook', requirements: ['step' => '[1-4]'], defaults: ['step' => 1], methods: ['GET', 'POST'])]
    public function __invoke(int $step, Request $request): Response
    {
        $session = $request->getSession();
        /** @var PublishEbookData $data */
        $data = $session->get(self::SESSION_KEY) ?? new PublishEbookData();

        // "Zapisz szkic" — mock action for now.
        if ($request->query->getBoolean('draft')) {
            $session->set(self::SESSION_KEY, $data);
            $this->addFlash('success', 'Szkic został zapisany.');

            return $this->redirectToRoute('app_publish_ebook', ['step' => $step]);
        }

        // Step 4 — review + publish (no-op).
        if (self::LAST_STEP === $step) {
            if ($request->isMethod('POST')) {
                $session->remove(self::SESSION_KEY);
                $this->addFlash('success', 'Gotowe! Twój eBook został przygotowany do publikacji.');

                return $this->redirectToRoute('app_home');
            }

            return $this->render('ebook/publish/step4.html.twig', ['data' => $data, 'step' => $step]);
        }

        if (3 === $step && null === $data->price) {
            $data->price = 29.99; // sensible default so the calculator starts populated
        }

        $form = $this->createForm(
            match ($step) {
                1 => FileStepType::class,
                2 => DetailsStepType::class,
                3 => PricingStepType::class,
            },
            $data,
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (1 === $step) {
                if ($file = $form->get('file')->getData()) {
                    $data->fileName = $file->getClientOriginalName();
                    $data->fileSize = $this->humanSize($file->getSize());
                }
                if ($cover = $form->get('cover')->getData()) {
                    $data->coverName = $cover->getClientOriginalName();
                }
            }

            if (2 === $step) {
                // Dynamic tag inputs live outside the form namespace.
                $data->keywords = array_values(array_filter(array_map('trim', $request->request->all('keywords'))));
                $data->genres = array_values(array_filter(array_map('trim', $request->request->all('genres'))));

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
        ]);
    }

    private function humanSize(?int $bytes): string
    {
        if (null === $bytes) {
            return '';
        }
        $mb = $bytes / 1_048_576;

        return $mb >= 1
            ? number_format($mb, 1, ',', ' ').' MB'
            : number_format($bytes / 1024, 0, ',', ' ').' KB';
    }
}
