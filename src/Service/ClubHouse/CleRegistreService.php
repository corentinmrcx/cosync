<?php declare(strict_types=1);

namespace App\Service\ClubHouse;

use App\DTO\CleDetention;
use App\DTO\CleMouvementData;
use App\DTO\CleRegistreStats;
use App\Entity\CleMouvement;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CleDetentionStatut;
use App\Enum\CleMouvementType;
use App\Repository\CleMouvementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Registre des clés du club house. L'historique est append-only : aucune méthode
 * de suppression ni de modification d'un mouvement existant. Une erreur de saisie
 * se corrige par un mouvement compensatoire.
 */
final class CleRegistreService
{
    public function __construct(
        private readonly CleMouvementRepository $mouvementRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @throws \InvalidArgumentException quantité nulle ou négative
     * @throws \DomainException          restitution ou perte supérieure au solde détenu
     */
    public function record(Dirigeant $dirigeant, CleMouvementData $data, ?User $createdBy): CleMouvement
    {
        if ($data->quantite <= 0) {
            throw new \InvalidArgumentException('La quantité doit être supérieure à zéro.');
        }

        if ($data->type !== CleMouvementType::REMISE) {
            $solde = $this->mouvementRepo->getSolde($dirigeant);
            if ($data->quantite > $solde) {
                throw new \DomainException(sprintf('%s %s ne détient que %d clé(s) : impossible d\'en enregistrer %d en %s.', $dirigeant->getNom(), $dirigeant->getPrenom(), $solde, $data->quantite, mb_strtolower($data->type->label())));
            }
        }

        $mouvement = (new CleMouvement())
            ->setDirigeant($dirigeant)
            ->setSeason($dirigeant->getSeason())
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
     * Détentions de la saison, restreintes à ce que l'admin cherche.
     *
     * @return CleDetention[]
     */
    public function rechercherDetentions(Season $season, string $recherche = '', ?CleDetentionStatut $statut = null): array
    {
        $detentions = $this->getDetentions($season);

        if ($recherche !== '') {
            $recherche = mb_strtolower($recherche);
            $detentions = array_filter(
                $detentions,
                static fn (CleDetention $detention): bool => str_contains(
                    mb_strtolower($detention->dirigeant->getNomPrenom()),
                    $recherche,
                ),
            );
        }

        if ($statut !== null) {
            $detentions = array_filter(
                $detentions,
                static fn (CleDetention $detention): bool => match ($statut) {
                    CleDetentionStatut::DETENTEUR => $detention->estDetenteur(),
                    CleDetentionStatut::SIGNATURE_MANQUANTE => $detention->estDetenteur() && !$detention->dirigeant->hasSignedAttestationCle(),
                    CleDetentionStatut::RESTITUE => !$detention->estDetenteur(),
                },
            );
        }

        return array_values($detentions);
    }

    /**
     * État de détention de toutes les personnes ayant au moins un mouvement dans
     * la saison, triées par nom. Une seule requête, pli séquentiel en PHP.
     *
     * @return CleDetention[]
     */
    public function getDetentions(Season $season): array
    {
        /** @var array<string, array{dirigeant: Dirigeant, mouvements: CleMouvement[]}> $parPersonne */
        $parPersonne = [];

        foreach ($this->mouvementRepo->findBySeasonOrdered($season) as $mouvement) {
            $key = (string) $mouvement->getDirigeant()->getUuid();
            $parPersonne[$key] ??= ['dirigeant' => $mouvement->getDirigeant(), 'mouvements' => []];
            $parPersonne[$key]['mouvements'][] = $mouvement;
        }

        return array_values(array_map(
            fn (array $groupe): CleDetention => $this->foldDetention($groupe['dirigeant'], $groupe['mouvements']),
            $parPersonne,
        ));
    }

    /** État de détention d'une seule personne, calculé sur son propre historique. */
    public function getDetentionDe(Dirigeant $dirigeant): CleDetention
    {
        // findByDirigeant trie du plus récent au plus ancien : le pli attend l'ordre inverse.
        return $this->foldDetention($dirigeant, array_reverse($this->mouvementRepo->findByDirigeant($dirigeant)));
    }

    /** @return CleDetention[] */
    public function getDetenteursActuels(Season $season): array
    {
        return array_values(array_filter(
            $this->getDetentions($season),
            static fn (CleDetention $detention): bool => $detention->estDetenteur(),
        ));
    }

    public function getStats(Season $season): CleRegistreStats
    {
        $enCirculation = 0;
        $detenteurs = 0;
        $perdues = 0;
        $restituees = 0;
        $signees = 0;

        foreach ($this->getDetentions($season) as $detention) {
            $perdues += $detention->pertes;
            $restituees += $detention->restitutions;

            if (!$detention->estDetenteur()) {
                continue;
            }

            $enCirculation += $detention->solde;
            ++$detenteurs;

            // Une attestation dépassée (clés remises depuis la signature) ne compte pas.
            if ($detention->attestationAJour()) {
                ++$signees;
            }
        }

        return new CleRegistreStats(
            clesEnCirculation: $enCirculation,
            nbDetenteurs: $detenteurs,
            clesPerdues: $perdues,
            clesRestituees: $restituees,
            nbAttestationsSignees: $signees,
            nbAttestationsManquantes: $detenteurs - $signees,
        );
    }

    /** @return CleMouvement[] */
    public function getHistorique(Dirigeant $dirigeant): array
    {
        return $this->mouvementRepo->findByDirigeant($dirigeant);
    }

    public function getSolde(Dirigeant $dirigeant): int
    {
        return $this->mouvementRepo->getSolde($dirigeant);
    }

    /**
     * « Depuis quand » n'est pas dérivable d'un agrégat : c'est la date de la remise
     * qui fait passer le solde de 0 à >0, remise à null dès que le solde retombe à 0.
     *
     * @param CleMouvement[] $mouvements ordonnés chronologiquement
     */
    private function foldDetention(Dirigeant $dirigeant, array $mouvements): CleDetention
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
            dirigeant: $dirigeant,
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
