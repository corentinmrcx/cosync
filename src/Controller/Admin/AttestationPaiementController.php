<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AttestationPaiement;
use App\Entity\Licencie;
use App\Form\AttestationPaiementType;
use App\Security\CsrfGuard;
use App\Service\Payment\AttestationPaiementService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/attestations-paiement', name: 'admin_attestation_paiement_')]
class AttestationPaiementController extends AbstractController
{
    public function __construct(
        private readonly AttestationPaiementService $service,
        private readonly CsrfGuard $csrf,
    ) {}

    /**
     * Écran d'émission. Le même formulaire porte deux boutons : l'aperçu, qui ouvre le PDF
     * sans rien enregistrer, et l'émission — d'où l'aiguillage sur la route appelée plutôt
     * que sur un champ caché.
     */
    #[Route('/nouvelle/{uuid}', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
        Request $request,
    ): Response {
        $motifBlocage = $this->service->motifBlocage($licencie);

        if ($motifBlocage !== null) {
            $this->addFlash('error', $motifBlocage);

            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
        }

        $form = $this->createForm(AttestationPaiementType::class, $this->service->prefill($licencie));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $attestation = $this->service->emettre($licencie, $data);

            if ($attestation->estEnvoyee()) {
                $this->addFlash('success', sprintf('Attestation générée et envoyée à %s.', $attestation->getEnvoyeeA()));
            } elseif ($data->email !== null && $data->email !== '') {
                // Le document existe et est archivé : seul le mail a échoué. Le dire
                // plutôt que d'annoncer un succès complet, l'admin doit pouvoir renvoyer.
                $this->addFlash('error', 'Attestation générée, mais le mail n\'est pas parti. Vérifiez la configuration SMTP puis renvoyez-la depuis la fiche.');
            } else {
                $this->addFlash('success', 'Attestation générée et archivée.');
            }

            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $licencie->getUuid()]);
        }

        return $this->render('admin/attestations/new.html.twig', [
            'form' => $form,
            'licencie' => $licencie,
            'transactions' => $this->service->transactionsDe($licencie),
        ]);
    }

    /** Aperçu du document tel qu'il sera émis, affiché dans un onglet, sans rien enregistrer. */
    #[Route('/nouvelle/{uuid}/apercu', name: 'preview', methods: ['POST'])]
    public function preview(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Licencie $licencie,
        Request $request,
    ): Response {
        $form = $this->createForm(AttestationPaiementType::class, $this->service->prefill($licencie));
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            return $this->render('admin/attestations/new.html.twig', [
                'form' => $form,
                'licencie' => $licencie,
                'transactions' => $this->service->transactionsDe($licencie),
            ]);
        }

        return $this->pdfResponse(
            $this->service->apercu($licencie, $form->getData()),
            'apercu_attestation_paiement.pdf',
        );
    }

    /**
     * Retéléchargement d'une attestation déjà émise. Le PDF est régénéré depuis les
     * valeurs figées : une fois archivé sur Drive, le fichier local a été supprimé.
     */
    #[Route('/{uuid}/telecharger', name: 'download', methods: ['GET'])]
    public function download(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AttestationPaiement $attestation,
    ): Response {
        return $this->pdfResponse(
            $this->service->telecharger($attestation),
            $this->service->nomFichier($attestation),
        );
    }

    #[Route('/{uuid}/renvoyer', name: 'resend', methods: ['POST'])]
    public function resend(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AttestationPaiement $attestation,
        Request $request,
    ): Response {
        $this->csrf->valider('attestation_paiement_resend_' . $attestation->getUuid(), $request);

        $email = trim((string) $request->request->get('email'));

        if ($email === '') {
            $this->addFlash('error', 'Aucune adresse renseignée : l\'attestation n\'a pas été envoyée.');
        } else {
            try {
                $this->service->renvoyer($attestation, $email);
                $this->addFlash('success', sprintf('Attestation renvoyée à %s.', $email));
            } catch (\Throwable) {
                $this->addFlash('error', 'Erreur lors de l\'envoi du mail. Vérifiez la configuration SMTP.');
            }
        }

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $attestation->getLicencie()->getUuid()]);
    }

    private function pdfResponse(string $contenu, string $nomFichier): Response
    {
        return new Response($contenu, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $nomFichier),
        ]);
    }
}
