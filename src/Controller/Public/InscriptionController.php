<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\DTO\InscriptionFormData;
use App\Enum\PaymentMode;
use App\Repository\LicencieRepository;
use App\Service\Form\InscriptionFormService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/inscription', name: 'public_inscription_')]
class InscriptionController extends AbstractController
{
    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $uuid, LicencieRepository $licencieRepo): Response
    {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null) {
            return $this->render('public/inscription/expired.html.twig');
        }

        $dossier = $licencie->getDossierClub();

        // Formulaire déjà soumis → rediriger vers la confirmation
        if ($dossier !== null && $dossier->getFormCompletedAt() !== null) {
            return $this->redirectToRoute('public_inscription_confirmation', ['uuid' => $uuid]);
        }

        if (!$licencie->isFormTokenValid()) {
            return $this->render('public/inscription/expired.html.twig');
        }

        $baseCosts = $licencie->getSeason()->getBaseCosts();
        $montant   = $licencie->isSeniorTariff()
            ? ($baseCosts['seniors'] ?? 0)
            : ($baseCosts['jeunes'] ?? 0);

        return $this->render('public/inscription/form.html.twig', [
            'licencie' => $licencie,
            'montant'  => $montant,
        ]);
    }

    #[Route('/{uuid}', name: 'submit', methods: ['POST'])]
    public function submit(string $uuid, Request $request, LicencieRepository $licencieRepo, InscriptionFormService $formService): Response
    {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || !$licencie->isFormTokenValid()) {
            return $this->render('public/inscription/expired.html.twig');
        }

        if (!$this->isCsrfTokenValid('inscription_submit', $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');
            return $this->redirectToRoute('public_inscription_show', ['uuid' => $uuid]);
        }

        $data = $this->buildFormData($request, $licencie->getCategory()->isJeune());

        if ($data === null) {
            $this->addFlash('error', 'Formulaire incomplet, veuillez remplir tous les champs.');
            return $this->redirectToRoute('public_inscription_show', ['uuid' => $uuid]);
        }

        $formService->submit($licencie, $data);

        return $this->redirectToRoute('public_inscription_confirmation', ['uuid' => $uuid]);
    }

    #[Route('/{uuid}/confirmation', name: 'confirmation', methods: ['GET'])]
    public function confirmation(string $uuid, LicencieRepository $licencieRepo): Response
    {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || $licencie->getDossierClub() === null) {
            return $this->render('public/inscription/expired.html.twig');
        }

        $baseCosts = $licencie->getSeason()->getBaseCosts();
        $montant   = $licencie->isSeniorTariff()
            ? ($baseCosts['seniors'] ?? 0)
            : ($baseCosts['jeunes'] ?? 0);

        return $this->render('public/inscription/confirmation.html.twig', [
            'licencie' => $licencie,
            'dossier'  => $licencie->getDossierClub(),
            'montant'  => $montant,
        ]);
    }

    private function buildFormData(Request $request, bool $isEcoleFoot): ?InscriptionFormData
    {
        $tailleHaut    = $request->request->get('taille_haut', '');
        $tailleBas     = $request->request->get('taille_bas', '');
        $pointure      = $request->request->get('pointure', '');
        $photoRaw      = $request->request->get('autorisation_photo');
        $signatureData = $request->request->get('signature_data', '');
        $paymentRaw    = $request->request->get('payment_intention', '');

        if ($tailleHaut === '' || $tailleBas === '' || $pointure === ''
            || $photoRaw === null || $signatureData === '' || $paymentRaw === '') {
            return null;
        }

        if (!str_starts_with($signatureData, 'data:image/') || strlen($signatureData) > 2_800_000) {
            return null;
        }

        $paymentMode = PaymentMode::tryFrom($paymentRaw);
        if ($paymentMode === null) {
            return null;
        }

        $transportDirig  = null;
        $transportParent = null;

        if ($isEcoleFoot) {
            $dirigRaw  = $request->request->get('autorisation_transport_dirigeants');
            $parentRaw = $request->request->get('autorisation_transport_parents');

            if ($dirigRaw === null || $parentRaw === null) {
                return null;
            }

            $transportDirig  = $dirigRaw === '1';
            $transportParent = $parentRaw === '1';
        }

        return new InscriptionFormData(
            tailleHaut:                       $tailleHaut,
            tailleBas:                        $tailleBas,
            pointure:                         $pointure,
            autorisationPhoto:                $photoRaw === '1',
            autorisationTransportDirigeants:  $transportDirig,
            autorisationTransportParents:     $transportParent,
            signatureData:                    $signatureData,
            paymentIntention:                 $paymentMode,
        );
    }
}
