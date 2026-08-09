<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Dirigeant;
use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Enum\DotationBesoinStatut;
use App\Repository\DirigeantRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\LicencieRepository;
use App\Service\DirigeantDossierCompletion;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Matérialise et tient à jour les besoins de dotation d'une personne à partir du modèle
 * résolu (DotationResolver). Idempotent : préserve les besoins déjà « donnés », met à jour
 * les « à donner », retire ceux qui ne sont plus dus.
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

    /** Recalcule pour toute la saison. Retourne le nombre de personnes ayant une dotation. */
    public function recomputeAll(Season $season): int
    {
        $count = 0;

        foreach ($this->licencieRepository->findValidatedBySeason($season) as $licencie) {
            if ($this->recomputeForLicencie($licencie)) {
                ++$count;
            }
        }

        foreach ($this->dirigeantRepository->findBySeason($season) as $dirigeant) {
            if ($this->dossierCompletion->isComplete($dirigeant) && $this->recomputeForDirigeant($dirigeant)) {
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
                ? $this->resolver->sizeFor($personne, $besoin->getStockItem()->getTypeVetement())
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
        $resolus = $this->resolver->resolveDotation($personne);
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
