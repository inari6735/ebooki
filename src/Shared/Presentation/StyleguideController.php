<?php declare(strict_types=1);

namespace App\Shared\Presentation;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Dev-only UI-kit catalog / living styleguide for the Bookly Design System.
 * Returns 404 outside the dev environment.
 */
final class StyleguideController extends AbstractController
{
    #[Route('/_kit', name: 'app_kit', methods: ['GET'])]
    public function __invoke(): Response
    {
        if ('dev' !== $this->getParameter('kernel.environment')) {
            throw $this->createNotFoundException();
        }

        return $this->render('kit/index.html.twig');
    }
}
