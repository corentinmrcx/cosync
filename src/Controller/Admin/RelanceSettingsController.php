<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\Permission;
use App\Form\RelanceSettingsType;
use App\Service\Referentiel\ClubSettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Réglages de la relance automatique — l'interrupteur du robot et ses deux bornes. */
#[Route('/admin/club/relances', name: 'admin_club_relances')]
#[IsGranted(Permission::CLUB_CONFIGURER->value)]
class RelanceSettingsController extends AbstractController
{
    public function __construct(
        private readonly ClubSettingsService $clubSettings,
    ) {}

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $settings = $this->clubSettings->get();

        $form = $this->createForm(RelanceSettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->clubSettings->enregistrer();
            $this->addFlash('success', $settings->isRelanceActive()
                ? 'Relance automatique activée : une passe par jour à 9 h.'
                : 'Relance automatique désactivée : plus aucun mail ne part de lui-même.');

            return $this->redirectToRoute('admin_club_relances');
        }

        return $this->render('admin/club/relances.html.twig', ['form' => $form]);
    }
}
