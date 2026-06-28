<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\DTO\AttestationTransportData;
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

    private function buildFormData(Request $request, bool $isJeune): ?InscriptionFormData
    {
        $tailleHaut    = $request->request->get('taille_haut', '');
        $tailleBas     = $request->request->get('taille_bas', '');
        $pointure      = $request->request->get('pointure', '');
        $photoRaw      = $request->request->get('autorisation_photo');
        $signatureData = $request->request->get('signature_data', '');

        if ($tailleHaut === '' || $tailleBas === '' || $pointure === ''
            || $photoRaw === null || $signatureData === '') {
            return null;
        }

        if (!str_starts_with($signatureData, 'data:image/') || strlen($signatureData) > 2_800_000) {
            return null;
        }

        $multiPayment = $request->request->get('multi_payment') === '1';

        if ($multiPayment) {
            $rawModes = (array) ($request->request->all()['payment_intentions'] ?? []);
            $modes    = [];
            foreach ($rawModes as $raw) {
                $m = PaymentMode::tryFrom((string) $raw);
                if ($m === null) {
                    return null;
                }
                $modes[] = $m;
            }
            if (count($modes) === 0) {
                return null;
            }
        } else {
            $rawMode = $request->request->get('payment_intention', '');
            $single  = PaymentMode::tryFrom($rawMode);
            if ($single === null) {
                return null;
            }
            $modes = [$single];
        }

        $transportDirig     = null;
        $transportParent    = null;
        $autorisationAccident = null;
        $volontaireTransport  = null;
        $attestationData      = null;

        if ($isJeune) {
            $dirigRaw    = $request->request->get('autorisation_transport_dirigeants');
            $parentRaw   = $request->request->get('autorisation_transport_parents');
            $accidentRaw = $request->request->get('autorisation_accident');
            $volRaw      = $request->request->get('volontaire_transport');

            if ($dirigRaw === null || $parentRaw === null || $accidentRaw === null || $volRaw === null) {
                return null;
            }

            $transportDirig      = $dirigRaw === '1';
            $transportParent     = $parentRaw === '1';
            $autorisationAccident = $accidentRaw === '1';
            $volontaireTransport  = $volRaw === '1';

            if ($volontaireTransport) {
                $nomConducteur    = trim($request->request->get('attestation_nom_conducteur', ''));
                $prenomConducteur = trim($request->request->get('attestation_prenom_conducteur', ''));
                $numPermis        = $request->request->get('attestation_num_permis', '');
                $assurance        = $request->request->get('attestation_assurance', '');
                $dateCTRaw        = $request->request->get('attestation_date_ct', '');
                $sigAttest        = $request->request->get('attestation_signature_data', '');
                $engagement       = $request->request->get('attestation_engagement') === '1';

                if ($nomConducteur === '' || $prenomConducteur === ''
                    || $numPermis === '' || $assurance === '' || $dateCTRaw === '' || $sigAttest === '') {
                    return null;
                }

                if (!str_starts_with($sigAttest, 'data:image/') || strlen($sigAttest) > 2_800_000) {
                    return null;
                }

                try {
                    $dateCT = new \DateTimeImmutable($dateCTRaw);
                } catch (\Exception) {
                    return null;
                }

                // Refuser une date de contrôle technique dans le futur
                if ($dateCT > new \DateTimeImmutable('today')) {
                    return null;
                }

                $attestationData = new AttestationTransportData(
                    nomConducteur:       $nomConducteur,
                    prenomConducteur:    $prenomConducteur,
                    numPermis:           $numPermis,
                    assuranceNomAdresse: $assurance,
                    dateCT:              $dateCT,
                    engagementPris:      $engagement,
                    signatureData:       $sigAttest,
                );
            }
        }

        return new InscriptionFormData(
            tailleHaut:                      $tailleHaut,
            tailleBas:                       $tailleBas,
            pointure:                        $pointure,
            autorisationPhoto:               $photoRaw === '1',
            autorisationTransportDirigeants: $transportDirig,
            autorisationTransportParents:    $transportParent,
            autorisationAccident:            $autorisationAccident,
            volontaireTransport:             $volontaireTransport,
            signatureData:                   $signatureData,
            paymentIntentions:               $modes,
            attestationTransport:            $attestationData,
        );
    }
}
