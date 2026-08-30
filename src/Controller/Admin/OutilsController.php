<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\Attribute\AccesLibre;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hub des outils du club : les fonctions transverses qui ne relèvent ni de l'effectif,
 * ni du stock, ni des clés.
 *
 * Il est au niveau du club et non de la saison, parce que c'est ainsi qu'on y pense —
 * « je veux sortir le planning », pas « je veux agir sur la saison 2026-2027 ». Les
 * outils qu'il abrite peuvent, eux, travailler dans la saison sélectionnée.
 *
 * Cet écran existe pour **accueillir les suivants** (planning d'utilisation du terrain,
 * etc.) : chaque outil s'y ajoute en une ligne, sans faire enfler le tableau de bord.
 */
#[Route('/admin/outils', name: 'admin_outils_')]
#[AccesLibre('Point de navigation : chaque carte du hub porte sa propre permission.')]
class OutilsController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/outils/index.html.twig');
    }
}
