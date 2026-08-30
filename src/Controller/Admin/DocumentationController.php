<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\Attribute\AccesLibre;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'admin_')]
#[AccesLibre('Aide en ligne : ne montre aucune donnée du club.')]
class DocumentationController extends AbstractController
{
    #[Route('/documentation', name: 'documentation', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/documentation/index.html.twig');
    }
}
