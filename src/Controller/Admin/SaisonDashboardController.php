<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\Entity\Season;
use App\Security\Attribute\AccesLibre;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point d'entrée de la saison sélectionnée. Le niveau club (tableau de bord racine) donne
 * accès aux saisons ; ce niveau-ci donne accès à ce qui se gère à l'intérieur d'une saison.
 */
#[Route('/admin/saison', name: 'admin_saison_')]
#[AccesLibre('Point de navigation : chaque carte du hub porte sa propre permission.')]
class SaisonDashboardController extends AbstractController
{
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(
        #[CurrentSeason] Season $season,
    ): Response {
        return $this->render('admin/saison/dashboard.html.twig', [
            'season' => $season,
        ]);
    }
}
