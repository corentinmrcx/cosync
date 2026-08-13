<?php declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accueil de la boutique du club. Il n'y a pour l'instant qu'un réglage — le lien —
 * mais la section existe pour accueillir la suite sans redéplacer les écrans.
 */
#[Route('/admin/boutique', name: 'admin_boutique_index')]
class BoutiqueController extends AbstractController
{
    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/boutique/index.html.twig');
    }
}
