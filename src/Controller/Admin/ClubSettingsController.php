<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\Permission;
use App\Form\ClubSettingsType;
use App\Service\Referentiel\ClubSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/club/coordonnees-bancaires', name: 'admin_club_rib')]
#[IsGranted(Permission::CLUB_RIB->value)]
class ClubSettingsController extends AbstractController
{
    public function __construct(
        private readonly ClubSettingsService $clubSettings,
    ) {}

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $settings = $this->clubSettings->get();

        $form = $this->createForm(ClubSettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->clubSettings->enregistrer();
            $this->addFlash('success', 'Coordonnées bancaires mises à jour.');

            return $this->redirectToRoute('admin_club_rib');
        }

        return $this->render('admin/club/coordonnees_bancaires.html.twig', [
            'form' => $form,
        ]);
    }
}
