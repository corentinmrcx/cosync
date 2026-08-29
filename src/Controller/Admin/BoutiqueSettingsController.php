<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\Permission;
use App\Form\BoutiqueSettingsType;
use App\Service\Referentiel\ClubSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/boutique/lien', name: 'admin_boutique_lien')]
#[IsGranted(Permission::BOUTIQUE_GERER->value)]
class BoutiqueSettingsController extends AbstractController
{
    public function __construct(
        private readonly ClubSettingsService $clubSettings,
    ) {}

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $settings = $this->clubSettings->get();

        $form = $this->createForm(BoutiqueSettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->clubSettings->enregistrer();
            $this->addFlash('success', 'Lien de la boutique mis à jour.');

            return $this->redirectToRoute('admin_boutique_lien');
        }

        return $this->render('admin/boutique/lien.html.twig', [
            'form' => $form,
        ]);
    }
}
