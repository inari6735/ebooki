<?php declare(strict_types=1);

namespace App\User\Presentation;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(Request $request): Response
    {
        return $this->render('user/login.html.twig', [
            'target_path' => $request->query->get('_target_path', ''),
        ]);
    }
}
