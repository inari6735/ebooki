<?php declare(strict_types=1);

namespace App\User\Presentation;

use App\Shared\Application\Bus\CommandBus;
use App\User\Application\Command\RegisterUser;
use App\User\Presentation\Form\RegistrationFormType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, CommandBus $commandBus): Response
    {
        $targetPath = (string) $request->query->get('_target_path', '');
        $wantsJson = $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');

        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{email: string, plainPassword: string} $data */
            $data = $form->getData();

            try {
                $commandBus->dispatch(new RegisterUser(
                    Uuid::v7()->toRfc4122(),
                    $data['email'],
                    $data['plainPassword'],
                ));

                // AJAX (in-page auth modal): let the caller take over (it auto-logs in).
                if ($wantsJson) {
                    return new JsonResponse(['ok' => true], Response::HTTP_CREATED);
                }

                $this->addFlash('success', 'Account created. You can now log in.');

                return $this->redirectToRoute('app_login', '' !== $targetPath ? ['_target_path' => $targetPath] : []);
            } catch (UniqueConstraintViolationException) {
                // Registration race: the UNIQUE index is the last line of defense.
                $form->get('email')->addError(new FormError('This email is already registered.'));
            }
        }

        if ($wantsJson && $form->isSubmitted()) {
            $errors = [];
            foreach ($form->getErrors(true) as $error) {
                $errors[] = $error->getMessage();
            }

            return new JsonResponse(['errors' => $errors ?: ['Nie udało się utworzyć konta.']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->render('user/register.html.twig', [
            'form' => $form,
            'target_path' => $targetPath,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
