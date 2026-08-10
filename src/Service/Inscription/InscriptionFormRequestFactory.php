<?php declare(strict_types=1);

namespace App\Service\Inscription;

use App\DTO\DotationChoixData;
use App\DTO\InscriptionFormData;
use App\Entity\Licencie;
use App\Enum\PaymentMode;
use App\Service\Document\SignatureCollector;
use Symfony\Component\HttpFoundation\Request;

/**
 * Construit le DTO de soumission du formulaire public à partir de la requête.
 *
 * Toute donnée manquante ou hors référentiel fait retourner null : le formulaire est
 * réaffiché avec un message, plutôt que d'enregistrer un dossier à moitié rempli.
 */
final class InscriptionFormRequestFactory
{
    public function __construct(
        private readonly SignatureCollector $signatureCollector,
        private readonly AttestationTransportRequestFactory $attestationFactory,
    ) {}

    public function fromRequest(Request $request, Licencie $licencie, bool $estJeune, DotationChoixData $dotation): ?InscriptionFormData
    {
        $tailleHaut = (string) $request->request->get('taille_haut', '');
        $tailleBas = (string) $request->request->get('taille_bas', '');
        $pointure = (string) $request->request->get('pointure', '');
        $photo = $request->request->get('autorisation_photo');

        if ($tailleHaut === '' || $tailleBas === '' || $pointure === '' || $photo === null) {
            return null;
        }

        $signatures = $this->signatureCollector->pourLicencie($request, $licencie);
        if ($signatures === null) {
            return null;
        }

        $modes = $this->modesDePaiement($request);
        if ($modes === null) {
            return null;
        }

        $autorisations = $estJeune ? $this->autorisationsJeune($request) : new AutorisationsJeune();
        if ($autorisations === null) {
            return null;
        }

        $attestation = null;
        if ($autorisations->volontaireTransport === true) {
            $attestation = $this->attestationFactory->fromRequest($request);
            if ($attestation === null) {
                return null;
            }
        }

        return new InscriptionFormData(
            tailleHaut: $tailleHaut,
            tailleBas: $tailleBas,
            pointure: $pointure,
            autorisationPhoto: $photo === '1',
            autorisationTransportDirigeants: $autorisations->transportDirigeants,
            autorisationTransportParents: $autorisations->transportParents,
            autorisationAccident: $autorisations->accident,
            volontaireTransport: $autorisations->volontaireTransport,
            documentSignatures: $signatures,
            paymentIntentions: $modes,
            paymentAutrePrecision: $this->precisionAutre($request, $modes),
            attestationTransport: $attestation,
            dotationChoix: $dotation->choix,
            dotationPersonnalisation: $dotation->personnalisation,
        );
    }

    /** @return list<PaymentMode>|null */
    private function modesDePaiement(Request $request): ?array
    {
        // Le bouton « payer par carte » vaut choix du mode : aucun radio n'est coché.
        if ($request->request->get('pay_online') === '1') {
            return [PaymentMode::CB_ONLINE];
        }

        if ($request->request->get('multi_payment') === '1') {
            $modes = [];
            foreach ((array) ($request->request->all()['payment_intentions'] ?? []) as $brut) {
                $mode = PaymentMode::tryFrom((string) $brut);
                if ($mode === null) {
                    return null;
                }
                $modes[] = $mode;
            }

            return $modes === [] ? null : $modes;
        }

        $mode = PaymentMode::tryFrom((string) $request->request->get('payment_intention', ''));

        return $mode === null ? null : [$mode];
    }

    /**
     * Précision libre du mode « Autre ». Le formulaire la réclame côté client, mais une saisie
     * vide n'est pas rejetée ici : mieux vaut un dossier à préciser de vive voix qu'une
     * inscription refusée à la dernière étape.
     *
     * @param list<PaymentMode> $modes
     */
    private function precisionAutre(Request $request, array $modes): ?string
    {
        if (!in_array(PaymentMode::AUTRE, $modes, true)) {
            return null;
        }

        $precision = trim((string) $request->request->get('payment_autre_precision', ''));

        return $precision === '' ? null : mb_substr($precision, 0, 100);
    }

    /** Les quatre autorisations parentales sont obligatoires ensemble, ou pas du tout. */
    private function autorisationsJeune(Request $request): ?AutorisationsJeune
    {
        $dirigeants = $request->request->get('autorisation_transport_dirigeants');
        $parents = $request->request->get('autorisation_transport_parents');
        $accident = $request->request->get('autorisation_accident');
        $volontaire = $request->request->get('volontaire_transport');

        if ($dirigeants === null || $parents === null || $accident === null || $volontaire === null) {
            return null;
        }

        return new AutorisationsJeune(
            $dirigeants === '1',
            $parents === '1',
            $accident === '1',
            $volontaire === '1',
        );
    }
}
