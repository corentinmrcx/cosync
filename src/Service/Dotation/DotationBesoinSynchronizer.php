<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\Entity\Dirigeant;
use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Enum\DotationBesoinStatut;
use App\Enum\LicenceStatus;
use App\Repository\DirigeantRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\LicencieRepository;
use App\Service\Dirigeant\DirigeantDossierCompletion;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Matérialise et tient à jour les besoins de dotation d'une personne à partir du modèle
 * résolu (DotationResolver). Idempotent : préserve les besoins déjà « donnés », met à jour
 * les « à donner », retire ceux qui ne sont plus dus.
 *
 * C'est ici, et nulle part ailleurs, que se juge le **droit à la dotation** : licence
 * validée pour un licencié, dossier complet pour un dirigeant. Les appelants n'ont pas à
 * refaire ce test — plusieurs l'oubliaient (correction de la nature de licence, rattrapage
 * d'affectation d'équipe) et matérialisaient le kit de gens qui n'avaient pas encore rempli
 * leur formulaire. Une personne non éligible est traitée comme n'ayant rien de dû : ses
 * besoins « à donner » sont retirés, ceux déjà remis sont conservés.
 */
final class DotationBesoinSynchronizer
{
    public function __construct(
        private readonly DotationResolver $resolver,
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly LicencieRepository $licencieRepository,
        private readonly DirigeantRepository $dirigeantRepository,
        private readonly DirigeantDossierCompletion $dossierCompletion,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return bool true si la personne est concernée par au moins une dotation */
    public function recomputeForLicencie(Licencie $licencie): bool
    {
        return $this->recompute($licencie, $this->besoinRepository->findForLicencie($licencie));
    }

    /** @return bool true si la personne est concernée par au moins une dotation */
    public function recomputeForDirigeant(Dirigeant $dirigeant): bool
    {
        return $this->recompute($dirigeant, $this->besoinRepository->findForDirigeant($dirigeant));
    }

    /**
     * Recalcule pour toute la saison. Retourne le nombre de personnes ayant une dotation.
     *
     * Balaie **tout** l'effectif, pas seulement les personnes éligibles : c'est ce qui fait
     * du bouton « Recalculer les besoins » un vrai bouton de réparation. Un besoin matérialisé
     * à tort pour une licence non validée est retiré par ce passage.
     */
    public function recomputeAll(Season $season): int
    {
        $count = 0;

        foreach ($this->licencieRepository->findBySeason($season) as $licencie) {
            if ($this->recomputeForLicencie($licencie)) {
                ++$count;
            }
        }

        foreach ($this->dirigeantRepository->findBySeason($season) as $dirigeant) {
            if ($this->recomputeForDirigeant($dirigeant)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Met à jour silencieusement les tailles depuis le dossier en cours pour tous les besoins
     * « à donner » non verrouillés manuellement. Appelé à l'affichage du suivi.
     */
    public function syncTaillesFromDossiers(Season $season): void
    {
        $changed = false;

        foreach ($this->besoinRepository->findBySeason($season) as $besoin) {
            if ($besoin->getStatut() !== DotationBesoinStatut::A_DONNER || $besoin->isTailleManuelle()) {
                continue;
            }

            $personne = $besoin->getLicencie() ?? $besoin->getDirigeant();
            $taille = $personne !== null
                ? $this->resolver->sizeFor($personne, $besoin->getStockItem())
                : null;

            if ($taille !== $besoin->getTaille()) {
                $besoin->setTaille($taille);
                $changed = true;
            }
        }

        if ($changed) {
            $this->em->flush();
        }
    }

    /**
     * @param DotationBesoin[] $existants
     *
     * @return bool true si la personne est concernée par au moins une dotation
     */
    private function recompute(Licencie|Dirigeant $personne, array $existants): bool
    {
        // Non éligible : rien n'est dû. On passe quand même par la purge, pour que le
        // recalcul rattrape les besoins matérialisés à tort par une version antérieure.
        $resolus = $this->aDroitALaDotation($personne)
            ? $this->resolver->resolveDotation($personne)
            : [];
        $existantsParEmplacement = $this->indexerParEmplacement($existants);
        $emplacementsResolus = [];

        foreach ($resolus as $ligne) {
            $emplacement = $this->emplacementDe($ligne['groupeChoix'], $ligne['stockItem']);
            $emplacementsResolus[$emplacement] = true;

            $besoinsDeLEmplacement = $existantsParEmplacement[$emplacement] ?? [];
            $aMettreAJour = $this->trouverBesoinAMettreAJour($besoinsDeLEmplacement);

            if ($aMettreAJour !== null) {
                $this->realigner($aMettreAJour, $ligne);
            } elseif ($besoinsDeLEmplacement === []) {
                $this->creerBesoin($personne, $ligne);
            }
            // Sinon, l'emplacement n'a que des besoins déjà donnés : on n'y touche pas.
        }

        $this->purgerBesoinsObsoletes($existants, $emplacementsResolus);

        $this->em->flush();

        return $resolus !== [];
    }

    /**
     * Le kit n'est dû qu'à partir du moment où la personne est entrée dans l'effectif pour de
     * bon : licence validée (donc payée, ou validée à la main) côté licencié, dossier complet
     * côté dirigeant. Avant cela, le suivi de dotation annoncerait au club des sorties de
     * stock à préparer pour des inscriptions qui ne sont pas encore acquises.
     */
    private function aDroitALaDotation(Licencie|Dirigeant $personne): bool
    {
        if ($personne instanceof Licencie) {
            return $personne->getDossierClub()?->getStatus() === LicenceStatus::VALIDATED;
        }

        return $this->dossierCompletion->isComplete($personne);
    }

    /**
     * Indexe les besoins existants par emplacement : un groupe de choix « 1 parmi N » compte
     * pour un seul emplacement, quelle que soit l'option retenue. Évite les doublons quand le
     * choix change après qu'une option a déjà été donnée.
     *
     * @param DotationBesoin[] $existants
     *
     * @return array<string, DotationBesoin[]>
     */
    private function indexerParEmplacement(array $existants): array
    {
        $index = [];

        foreach ($existants as $besoin) {
            $index[$this->emplacementDe($besoin->getGroupeChoix(), $besoin->getStockItem())][] = $besoin;
        }

        return $index;
    }

    /** @param DotationBesoin[] $besoins */
    private function trouverBesoinAMettreAJour(array $besoins): ?DotationBesoin
    {
        foreach ($besoins as $besoin) {
            if ($besoin->getStatut() === DotationBesoinStatut::A_DONNER) {
                return $besoin;
            }
        }

        return null;
    }

    /**
     * Le choix a pu changer : on réaligne l'article sur l'option retenue. Le texte de flocage
     * suit, pour qu'une faute de frappe corrigée se propage tant que l'article n'est pas remis.
     *
     * @param array<string, mixed> $ligne
     */
    private function realigner(DotationBesoin $besoin, array $ligne): void
    {
        $besoin
            ->setStockItem($ligne['stockItem'])
            ->setQuantite($ligne['quantite'])
            ->setGroupeChoix($ligne['groupeChoix'])
            ->setPersonnalisation($ligne['personnalisation']);

        // La taille saisie à la main par l'admin prime sur celle déduite du dossier.
        if (!$besoin->isTailleManuelle()) {
            $besoin->setTaille($ligne['taille']);
        }
    }

    /** @param array<string, mixed> $ligne */
    private function creerBesoin(Licencie|Dirigeant $personne, array $ligne): void
    {
        $besoin = (new DotationBesoin())
            ->setSeason($personne->getSeason())
            ->setStockItem($ligne['stockItem'])
            ->setQuantite($ligne['quantite'])
            ->setTaille($ligne['taille'])
            ->setGroupeChoix($ligne['groupeChoix'])
            ->setPersonnalisation($ligne['personnalisation']);

        if ($personne instanceof Licencie) {
            $besoin->setLicencie($personne);
        } else {
            $besoin->setDirigeant($personne);
        }

        $this->em->persist($besoin);
    }

    /**
     * @param DotationBesoin[]     $existants
     * @param array<string, true>  $emplacementsResolus
     */
    private function purgerBesoinsObsoletes(array $existants, array $emplacementsResolus): void
    {
        foreach ($existants as $besoin) {
            $emplacement = $this->emplacementDe($besoin->getGroupeChoix(), $besoin->getStockItem());

            if ($besoin->getStatut() === DotationBesoinStatut::A_DONNER && !isset($emplacementsResolus[$emplacement])) {
                $this->em->remove($besoin);
            }
        }
    }

    /**
     * Clé d'emplacement : un groupe de choix = un emplacement unique, sinon l'article.
     *
     * Le texte de personnalisation n'entre volontairement PAS dans cette clé : sinon corriger
     * une faute de frappe supprimerait le besoin pour en recréer un autre, perdant au passage
     * le statut « donné », la taille manuelle et l'historique.
     */
    private function emplacementDe(?string $groupeChoix, StockItem $item): string
    {
        return $groupeChoix !== null ? 'g:' . $groupeChoix : 'i:' . $item->getId();
    }
}
