<?php declare(strict_types=1);

namespace App\Controller\Public;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages légales publiques : politique de confidentialité (art. 13 RGPD) et
 * mentions légales (LCEN). Contenu statique, versionné dans les templates.
 */
#[Route(name: 'public_legal_')]
final class LegalController extends AbstractController
{
    #[Route('/politique-de-confidentialite', name: 'confidentialite', methods: ['GET'])]
    public function confidentialite(): Response
    {
        return $this->render('public/legal/confidentialite.html.twig');
    }

    #[Route('/mentions-legales', name: 'mentions', methods: ['GET'])]
    public function mentions(): Response
    {
        return $this->render('public/legal/mentions.html.twig');
    }
}
