<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\Permission;
use App\Form\ClubIdentiteType;
use App\Security\CsrfGuard;
use App\Service\Referentiel\ClubSettingsService;
use App\Service\Referentiel\SignatureCachetStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/club/identite', name: 'admin_club_identite_')]
#[IsGranted(Permission::CLUB_CONFIGURER->value)]
class ClubIdentiteController extends AbstractController
{
    public function __construct(
        private readonly ClubSettingsService $clubSettings,
        private readonly SignatureCachetStorage $signatures,
        private readonly CsrfGuard $csrf,
    ) {}

    #[Route('', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $settings = $this->clubSettings->get();

        $form = $this->createForm(ClubIdentiteType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->clubSettings->enregistrer();

            $fichier = $form->get('signatureFichier')->getData();
            if ($fichier instanceof UploadedFile) {
                $this->clubSettings->remplacerSignature($fichier);
            }

            $this->addFlash('success', 'Identité de l\'association mise à jour.');

            return $this->redirectToRoute('admin_club_identite_edit');
        }

        return $this->render('admin/club/identite.html.twig', [
            'form' => $form,
            'settings' => $settings,
            // Aperçu de la signature déjà en place : elle vit hors de public/, elle ne
            // peut donc pas être servie par une URL — on l'embarque dans la page.
            'signatureDataUrl' => $this->signatures->dataUrl($settings->getSignatureCachetFichier()),
        ]);
    }

    #[Route('/signature/supprimer', name: 'delete_signature', methods: ['POST'])]
    public function deleteSignature(Request $request): Response
    {
        $this->csrf->valider('club_identite_delete_signature', $request);

        $this->clubSettings->supprimerSignature();
        $this->addFlash('success', 'Signature supprimée. Les prochaines attestations s\'imprimeront avec un cadre à signer.');

        return $this->redirectToRoute('admin_club_identite_edit');
    }
}
