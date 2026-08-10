<?php declare(strict_types=1);

namespace App\Service\Cle;

use App\DTO\CleDetention;
use App\DTO\CleMouvementData;
use App\Entity\CleMouvement;
use App\Entity\Detenteur;
use App\Entity\User;
use App\Enum\CleMouvementType;
use App\Repository\CleMouvementRepository;
use App\Repository\DetenteurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registre des clés du local, au niveau du club et sur toute sa durée de vie.
 *
 * L'historique est append-only : aucune méthode de suppression ni de modification
 * d'un mouvement existant. Une erreur de saisie se corrige par un mouvement
 * compensatoire.
 *
 * Ce service ne connaît pas la notion de saison, volontairement : le solde d'une
 * personne est la somme de tout ce qu'elle a reçu et rendu depuis toujours.
 */
final class CleRegistreService
{
    public function __construct(
        private readonly CleMouvementRepository $mouvementRepo,
        private readonly DetenteurRepository $detenteurRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @throws \InvalidArgumentException quantité nulle ou négative
     * @throws \DomainException          restitution ou perte supérieure au solde détenu
     */
    public function record(Detenteur $detenteur, CleMouvementData $data, ?User $createdBy): CleMouvement
    {
        if ($data->quantite <= 0) {
            throw new \InvalidArgumentException('La quantité doit être supérieure à zéro.');
        }

        if ($data->type !== CleMouvementType::REMISE) {
            $solde = $this->mouvementRepo->getSolde($detenteur);
            if ($data->quantite > $solde) {
                throw new \DomainException(sprintf('%s ne détient que %d clé(s) : impossible d\'en enregistrer %d en %s.', $detenteur->getNomPrenom(), $solde, $data->quantite, mb_strtolower($data->type->label())));
            }
        }

        $mouvement = (new CleMouvement())
            ->setDetenteur($detenteur)
            ->setType($data->type)
            ->setQuantite($data->quantite)
            ->setDateMouvement($data->dateMouvement)
            ->setNote($data->note)
            ->setCreatedBy($createdBy);

        $this->em->persist($mouvement);
        $this->em->flush();

        return $mouvement;
    }

    /**
     * État de détention de tous les détenteurs connus, triés par nom.
     *
     * Un détenteur sans aucun mouvement y figure au solde zéro : il vient d'être
     * ajouté et on doit pouvoir lui remettre une clé depuis cette liste.
     *
     * @return CleDetention[]
     */
    public function getDetentions(): array
    {
        /** @var array<int, CleMouvement[]> $parPersonne */
        $parPersonne = [];

        foreach ($this->mouvementRepo->findAllOrdered() as $mouvement) {
            $parPersonne[$mouvement->getDetenteur()->getId()][] = $mouvement;
        }

        return array_map(
            fn (Detenteur $detenteur): CleDetention => $this->foldDetention(
                $detenteur,
                $parPersonne[$detenteur->getId()] ?? [],
            ),
            $this->detenteurRepo->findAllOrdered(),
        );
    }

    /** État de détention d'une seule personne, calculé sur son propre historique. */
    public function getDetentionDe(Detenteur $detenteur): CleDetention
    {
        // findByDetenteur trie du plus récent au plus ancien : le pli attend l'ordre inverse.
        return $this->foldDetention($detenteur, array_reverse($this->mouvementRepo->findByDetenteur($detenteur)));
    }

    /** @return CleMouvement[] */
    public function getHistorique(Detenteur $detenteur): array
    {
        return $this->mouvementRepo->findByDetenteur($detenteur);
    }

    /** @return CleMouvement[] */
    public function getMouvementsRecents(int $limit): array
    {
        return $this->mouvementRepo->findRecents($limit);
    }

    public function getSolde(Detenteur $detenteur): int
    {
        return $this->mouvementRepo->getSolde($detenteur);
    }

    /**
     * « Depuis quand » n'est pas dérivable d'un agrégat : c'est la date de la remise
     * qui fait passer le solde de 0 à >0, remise à null dès que le solde retombe à 0.
     *
     * @param CleMouvement[] $mouvements ordonnés chronologiquement
     */
    private function foldDetention(Detenteur $detenteur, array $mouvements): CleDetention
    {
        $solde = 0;
        $depuis = null;
        $derniereRemise = null;
        $remises = 0;
        $restitutions = 0;
        $pertes = 0;
        $dernier = null;

        foreach ($mouvements as $mouvement) {
            $quantite = $mouvement->getQuantite();

            match ($mouvement->getType()) {
                CleMouvementType::REMISE => $remises += $quantite,
                CleMouvementType::RESTITUTION => $restitutions += $quantite,
                CleMouvementType::PERTE => $pertes += $quantite,
            };

            $avant = $solde;
            $solde += $mouvement->getType()->impact() * $quantite;

            if ($mouvement->getType() === CleMouvementType::REMISE) {
                $derniereRemise = $mouvement->getDateMouvement();
            }
            if ($avant === 0 && $solde > 0) {
                $depuis = $mouvement->getDateMouvement();
            }
            if ($solde <= 0) {
                $depuis = null;
                $derniereRemise = null;
            }

            $dernier = $mouvement->getDateMouvement();
        }

        return new CleDetention(
            detenteur: $detenteur,
            remises: $remises,
            restitutions: $restitutions,
            pertes: $pertes,
            solde: $solde,
            detenteurDepuis: $depuis,
            derniereRemiseLe: $derniereRemise,
            dernierMouvementLe: $dernier,
        );
    }
}
