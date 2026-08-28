<?php declare(strict_types=1);

namespace App\Service\Effectif;

use App\DTO\Effectif\ResultatSuppression;
use App\DTO\Effectif\SuppressionFiche;
use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Enum\LicenceStatus;
use App\Repository\AttestationPaiementRepository;
use App\Repository\DocumentSignatureRepository;
use App\Repository\DotationAffectationRepository;
use App\Repository\StockMovementRepository;
use App\Repository\TransactionRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Retirer de l'effectif une fiche qui n'aurait jamais dû y entrer.
 *
 * Le cas visé est celui de l'import fautif : des dossiers FootClubs encore au stade « Prise de
 * contact » importés par mégarde, qui ne deviendront peut-être jamais des licences. Rien ne les
 * distingue ensuite des vraies fiches, et ils faussent les effectifs comme les relances.
 *
 * La règle tient en une phrase : **une fiche ne se supprime que si rien ne s'y est passé**. Dès
 * qu'un mail est parti, qu'un formulaire est rempli, qu'une signature, un paiement ou une sortie
 * de stock la touche, la suppression est refusée — pas repoussée à un écran de confirmation plus
 * insistant : refusée. Ce n'est plus une erreur de saisie mais une histoire, et l'écran l'annonce
 * avec son motif, comme `StockItemService::analyserSuppression()` le fait pour un article.
 *
 * L'analyse est rejouée juste avant chaque suppression : l'écran de confirmation dit ce qui était
 * vrai à son affichage, pas ce qui l'est au moment d'agir.
 */
final class SuppressionFicheService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DocumentSignatureRepository $signatureRepo,
        private readonly TransactionRepository $transactionRepo,
        private readonly StockMovementRepository $mouvementRepo,
        private readonly DotationAffectationRepository $affectationRepo,
        private readonly AttestationPaiementRepository $attestationRepo,
    ) {}

    /**
     * @param list<Licencie|Dirigeant> $fiches
     *
     * @return list<SuppressionFiche>
     */
    public function analyserLot(array $fiches): array
    {
        return array_map($this->analyser(...), $fiches);
    }

    public function analyser(Licencie|Dirigeant $fiche): SuppressionFiche
    {
        $motif = $fiche instanceof Licencie
            ? $this->motifRefusLicencie($fiche)
            : $this->motifRefusDirigeant($fiche);

        return $motif === null
            ? SuppressionFiche::autorisee($fiche)
            : SuppressionFiche::refusee($fiche, $motif);
    }

    /**
     * Supprime les fiches que l'analyse autorise, épargne les autres. Tout ou rien serait ici le
     * mauvais choix : une seule fiche devenue intouchable annulerait le ménage des vingt autres.
     *
     * @param list<Licencie|Dirigeant> $fiches
     */
    public function supprimerLot(array $fiches): ResultatSuppression
    {
        $supprimees = 0;
        $refusees = [];

        foreach ($fiches as $fiche) {
            $analyse = $this->analyser($fiche);

            if (!$analyse->supprimable) {
                $refusees[] = sprintf('%s (%s)', $analyse->nomPrenom(), $analyse->motifRefus);

                continue;
            }

            $this->supprimer($fiche);
            ++$supprimees;
        }

        try {
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException) {
            // Un rattachement que l'analyse ne connaît pas encore (colonne historique, module
            // ajouté depuis) : rien n'est supprimé plutôt que de laisser la base à moitié défaite.
            $this->em->clear();

            throw new \DomainException('Suppression impossible : une de ces fiches est encore rattachée à des données du club. Aucune fiche n\'a été supprimée.');
        }

        return new ResultatSuppression($supprimees, $refusees);
    }

    /**
     * Le dossier club n'existe que par son licencié : il part avec lui, sans quoi la suppression
     * bute sur sa clé étrangère. Les besoins et affectations de dotation sont, eux, effacés en
     * cascade par la base — l'analyse a garanti qu'il n'en reste aucun d'intéressant.
     */
    private function supprimer(Licencie|Dirigeant $fiche): void
    {
        if ($fiche instanceof Licencie && $fiche->getDossierClub() !== null) {
            $this->em->remove($fiche->getDossierClub());
        }

        $this->em->remove($fiche);
    }

    /** Premier motif rencontré, du plus parlant au plus technique — null si la fiche est vierge. */
    private function motifRefusLicencie(Licencie $licencie): ?string
    {
        $lienEnvoyeLe = $licencie->getLinkSentAt();
        if ($lienEnvoyeLe !== null) {
            return sprintf('son lien d\'inscription lui a été envoyé le %s', $lienEnvoyeLe->format('d/m/Y'));
        }

        $boutiqueAnnonceeLe = $licencie->getBoutiqueAnnonceeAt();
        if ($boutiqueAnnonceeLe !== null) {
            return sprintf('l\'ouverture de la boutique lui a été annoncée le %s', $boutiqueAnnonceeLe->format('d/m/Y'));
        }

        $dossier = $licencie->getDossierClub();
        if ($dossier !== null) {
            $rempliLe = $dossier->getFormCompletedAt();
            if ($rempliLe !== null) {
                return sprintf('son formulaire d\'inscription a été rempli le %s', $rempliLe->format('d/m/Y'));
            }

            if ($dossier->getStatus() !== LicenceStatus::IMPORTED) {
                return sprintf('son dossier est au statut « %s »', $dossier->getStatus()->label());
            }

            $checkoutLe = $dossier->getHelloassoCheckoutStartedAt();
            if ($checkoutLe !== null) {
                return sprintf('un paiement en ligne a été engagé le %s', $checkoutLe->format('d/m/Y'));
            }
        }

        return $this->motifRattachements([
            'il a signé %d document%s' => $this->signatureRepo->count(['licencie' => $licencie]),
            '%d paiement%s est enregistré à son nom' => $this->transactionRepo->count(['licencie' => $licencie]),
            'du matériel lui a été remis (%d mouvement%s de stock)' => $this->mouvementRepo->count(['licencie' => $licencie]),
            'une dotation lui est affectée nominativement (%d affectation%s)' => $this->affectationRepo->count(['licencie' => $licencie]),
            // Une attestation suppose un paiement, mais celui-ci a pu être supprimé depuis :
            // sans ce compte, la fiche paraissait vierge et la suppression butait sur la
            // clé étrangère, avec le message générique du rattrapage pour toute explication.
            '%d attestation%s de paiement a été émise à son nom' => $this->attestationRepo->count(['licencie' => $licencie]),
        ]);
    }

    private function motifRefusDirigeant(Dirigeant $dirigeant): ?string
    {
        $lienEnvoyeLe = $dirigeant->getLinkSentAt();
        if ($lienEnvoyeLe !== null) {
            return sprintf('son lien lui a été envoyé le %s', $lienEnvoyeLe->format('d/m/Y'));
        }

        $rempliLe = $dirigeant->getFormCompletedAt();
        if ($rempliLe !== null) {
            return sprintf('son formulaire a été rempli le %s', $rempliLe->format('d/m/Y'));
        }

        return $this->motifRattachements([
            'il a signé %d document%s' => $this->signatureRepo->count(['dirigeant' => $dirigeant]),
            'du matériel lui a été remis (%d mouvement%s de stock)' => $this->mouvementRepo->count(['dirigeant' => $dirigeant]),
            'une dotation lui est affectée nominativement (%d affectation%s)' => $this->affectationRepo->count(['dirigeant' => $dirigeant]),
        ]);
    }

    /**
     * @param array<string, int> $comptes gabarit de motif => nombre de rattachements
     */
    private function motifRattachements(array $comptes): ?string
    {
        foreach ($comptes as $gabarit => $compte) {
            if ($compte > 0) {
                return sprintf($gabarit, $compte, $compte > 1 ? 's' : '');
            }
        }

        return null;
    }
}
