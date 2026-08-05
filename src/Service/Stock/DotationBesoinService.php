<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Dirigeant;
use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\DotationBesoinStatut;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Repository\DirigeantRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\LicencieRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Matérialise et tient à jour les besoins de dotation d'une personne à partir
 * du modèle résolu (DotationResolver). Idempotent : préserve les besoins déjà
 * « donnés », met à jour les « à donner », retire ceux qui ne sont plus dus.
 */
final class DotationBesoinService
{
    public function __construct(
        private readonly DotationResolver $resolver,
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly StockService $stockService,
        private readonly LicencieRepository $licencieRepository,
        private readonly DirigeantRepository $dirigeantRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @return bool true si la personne est concernée par au moins une dotation. */
    public function recomputeForLicencie(Licencie $licencie): bool
    {
        return $this->recompute($licencie, $this->besoinRepository->findForLicencie($licencie));
    }

    /** @return bool true si la personne est concernée par au moins une dotation. */
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
                $count++;
            }
        }

        foreach ($this->dirigeantRepository->findBySeason($season) as $dirigeant) {
            if ($dirigeant->isPublicFormComplete() && $this->recomputeForDirigeant($dirigeant)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Statut synthétique de la dotation d'un licencié, pour un badge sur sa fiche.
     * Retourne null si aucune dotation ne le concerne (pas de kit pour son équipe/catégorie).
     *
     * @return array{statut: string, donnes: int, total: int}|null
     *         statut ∈ remise | partielle | attente | a_preparer
     */
    public function statutFicheLicencie(Licencie $licencie): ?array
    {
        $seasonId = $licencie->getSeason()->getId();
        $besoins = array_filter(
            $this->besoinRepository->findForLicencie($licencie),
            static fn (DotationBesoin $b): bool => $b->getSeason()->getId() === $seasonId,
        );

        // Besoins pas encore matérialisés (licencié non validé) : on regarde si un kit s'applique.
        if ($besoins === []) {
            return $this->resolver->resolveDotation($licencie) !== []
                ? ['statut' => 'a_preparer', 'donnes' => 0, 'total' => 0]
                : null;
        }

        $total  = count($besoins);
        $donnes = count(array_filter(
            $besoins,
            static fn (DotationBesoin $b): bool => $b->getStatut() === DotationBesoinStatut::DONNE,
        ));

        $statut = match (true) {
            $donnes === $total => 'remise',
            $donnes === 0      => 'attente',
            default            => 'partielle',
        };

        return ['statut' => $statut, 'donnes' => $donnes, 'total' => $total];
    }

    /**
     * @param DotationBesoin[] $existants
     * @return bool true si la personne est concernée par au moins une dotation (modèle résolu non vide).
     */
    private function recompute(Licencie|Dirigeant $person, array $existants): bool
    {
        $resolved = $this->resolver->resolveDotation($person);

        // Indexe les besoins existants par « emplacement » : un groupe de choix « 1 parmi N »
        // compte pour un seul emplacement, quelle que soit l'option retenue. Sans groupe,
        // l'emplacement est l'article lui-même. Évite les doublons quand le choix change
        // après qu'une option a déjà été donnée.
        /** @var array<string, DotationBesoin[]> $existantsParSlot */
        $existantsParSlot = [];
        foreach ($existants as $besoin) {
            $existantsParSlot[$this->slotKey($besoin->getGroupeChoix(), $besoin->getStockItem()->getId())][] = $besoin;
        }

        $slotsResolus = [];

        foreach ($resolved as $ligne) {
            $item = $ligne['stockItem'];
            $slot = $this->slotKey($ligne['groupeChoix'], $item->getId());
            $slotsResolus[$slot] = true;

            $besoinsSlot = $existantsParSlot[$slot] ?? [];

            // Cherche un besoin « à donner » à mettre à jour
            $aMettreAJour = null;
            foreach ($besoinsSlot as $besoin) {
                if ($besoin->getStatut() === DotationBesoinStatut::A_DONNER) {
                    $aMettreAJour = $besoin;
                    break;
                }
            }

            if ($aMettreAJour !== null) {
                // Le choix a pu changer → on réaligne l'article sur l'option retenue.
                // Le texte de flocage suit : corriger une faute de frappe doit se propager
                // tant que l'article n'a pas été remis.
                $aMettreAJour
                    ->setStockItem($item)
                    ->setQuantite($ligne['quantite'])
                    ->setGroupeChoix($ligne['groupeChoix'])
                    ->setPersonnalisation($ligne['personnalisation']);
                // La taille saisie à la main par l'admin prime sur celle déduite du dossier.
                if (!$aMettreAJour->isTailleManuelle()) {
                    $aMettreAJour->setTaille($ligne['taille']);
                }
            } elseif ($besoinsSlot === []) {
                // Aucun besoin pour cet emplacement → en créer un
                $besoin = (new DotationBesoin())
                    ->setSeason($person->getSeason())
                    ->setStockItem($item)
                    ->setQuantite($ligne['quantite'])
                    ->setTaille($ligne['taille'])
                    ->setGroupeChoix($ligne['groupeChoix'])
                    ->setPersonnalisation($ligne['personnalisation']);

                if ($person instanceof Licencie) {
                    $besoin->setLicencie($person);
                } else {
                    $besoin->setDirigeant($person);
                }

                $this->em->persist($besoin);
            }
            // S'il n'existe que des besoins « donnés » pour cet emplacement → on les laisse (déjà remis).
        }

        // Retire les besoins « à donner » dont l'emplacement n'est plus dans le modèle résolu
        foreach ($existants as $besoin) {
            $slot = $this->slotKey($besoin->getGroupeChoix(), $besoin->getStockItem()->getId());
            if ($besoin->getStatut() === DotationBesoinStatut::A_DONNER && !isset($slotsResolus[$slot])) {
                $this->em->remove($besoin);
            }
        }

        $this->em->flush();

        return $resolved !== [];
    }

    /**
     * Clé d'emplacement : un groupe de choix = un emplacement unique, sinon l'article.
     *
     * Le texte de personnalisation n'entre volontairement PAS dans cette clé : sinon
     * corriger une faute de frappe supprimerait le besoin pour en recréer un autre, perdant
     * au passage le statut « donné », la taille manuelle et l'historique.
     */
    private function slotKey(?string $groupeChoix, int $itemId): string
    {
        return $groupeChoix !== null ? 'g:' . $groupeChoix : 'i:' . $itemId;
    }

    /**
     * Besoins encore à donner qui portent un texte de flocage : la liste à transmettre au
     * floqueur. Triée par personne, comme le suivi.
     *
     * @return DotationBesoin[]
     */
    public function getFlocages(Season $season): array
    {
        return array_values(array_filter(
            $this->besoinRepository->findBySeason($season),
            static fn (DotationBesoin $b): bool => $b->getPersonnalisation() !== null
                && $b->getStatut() === DotationBesoinStatut::A_DONNER,
        ));
    }

    /**
     * Corrige le texte de flocage d'un besoin depuis le suivi admin — pour rattraper une
     * faute de frappe du licencié avant la commande. Refusé une fois l'article remis :
     * le vêtement est déjà floqué, le texte porté par le besoin est la trace de ce qui a
     * réellement été donné.
     *
     * @throws \DomainException si le besoin est déjà marqué comme donné
     */
    public function updatePersonnalisation(DotationBesoin $besoin, ?string $texte): void
    {
        if ($besoin->getStatut() === DotationBesoinStatut::DONNE) {
            throw new \DomainException('Cet article a déjà été remis : son flocage ne peut plus être modifié.');
        }

        $normalise = $texte !== null ? trim((string) preg_replace('/\s+/u', ' ', $texte)) : '';
        $besoin->setPersonnalisation($normalise !== '' ? $normalise : null);

        $this->em->flush();
    }

    /**
     * Met à jour silencieusement les tailles depuis le dossier en cours pour tous les besoins
     * « à donner » non verrouillés manuellement. Appelé à l'affichage du suivi.
     */
    public function syncTaillesFromDossiers(Season $season): void
    {
        $changed = false;
        foreach ($this->besoinRepository->findBySeason($season) as $besoin) {
            if ($besoin->getStatut() !== DotationBesoinStatut::A_DONNER) {
                continue;
            }
            if ($besoin->isTailleManuelle()) {
                continue;
            }

            $person   = $besoin->getLicencie() ?? $besoin->getDirigeant();
            $resolved = $person !== null
                ? $this->resolver->sizeFor($person, $besoin->getStockItem()->getTypeVetement())
                : null;

            if ($resolved !== $besoin->getTaille()) {
                $besoin->setTaille($resolved);
                $changed = true;
            }
        }

        if ($changed) {
            $this->em->flush();
        }
    }

    /**
     * Regroupe les besoins de la saison par équipe pour l'écran de suivi.
     * Dans chaque équipe, les personnes sont triées par nom (via le tri du repository),
     * mais celles entièrement servies (tous leurs besoins « donnés ») sont renvoyées
     * en fin de liste.
     *
     * @return list<array{nom: string, besoins: list<DotationBesoin>, total: int, restants: int}>
     */
    public function getSuiviGroupes(Season $season): array
    {
        // findBySeason renvoie déjà les besoins triés par nom/prénom de personne.
        /** @var array<string, array<string, list<DotationBesoin>>> $parEquipe */
        $parEquipe = [];
        foreach ($this->besoinRepository->findBySeason($season) as $besoin) {
            $equipe   = $besoin->getTeamName() ?? 'Sans équipe';
            $personne = $besoin->getLicencie() ?? $besoin->getDirigeant();
            $cle      = $personne !== null ? (string) $personne->getUuid() : 'inconnu';
            $parEquipe[$equipe][$cle][] = $besoin;
        }
        ksort($parEquipe);

        $groupes = [];
        foreach ($parEquipe as $nom => $personnes) {
            $aServir = [];
            $servies = [];
            foreach ($personnes as $besoinsPersonne) {
                $tousDonnes = true;
                foreach ($besoinsPersonne as $b) {
                    if ($b->getStatut() !== DotationBesoinStatut::DONNE) {
                        $tousDonnes = false;
                        break;
                    }
                }
                if ($tousDonnes) {
                    $servies[] = $besoinsPersonne;
                } else {
                    $aServir[] = $besoinsPersonne;
                }
            }

            $ordonnes = [];
            $restants = 0;
            foreach (array_merge($aServir, $servies) as $besoinsPersonne) {
                foreach ($besoinsPersonne as $b) {
                    $ordonnes[] = $b;
                    if ($b->getStatut() !== DotationBesoinStatut::DONNE) {
                        $restants++;
                    }
                }
            }

            $groupes[] = [
                'nom'      => $nom,
                'besoins'  => $ordonnes,
                'total'    => count($ordonnes),
                'restants' => $restants,
            ];
        }

        return $groupes;
    }

    /**
     * Fixe (ou réinitialise) à la main la taille d'un besoin.
     * - « à donner » : une taille vide repasse en mode automatique (déduit du dossier au recalcul).
     * - « donné » : si la taille change, le mouvement de stock est rejoué (restitution à
     *   l'ancienne taille puis sortie à la nouvelle) pour rester cohérent avec le stock réel.
     */
    public function updateTaille(DotationBesoin $besoin, ?string $taille, ?User $user = null): void
    {
        $taille = trim((string) $taille) ?: null;

        if ($besoin->getStatut() === DotationBesoinStatut::DONNE) {
            if ($taille !== $besoin->getTaille()) {
                $this->rejoueMouvementTaille($besoin, $taille, $user);
            }
            $besoin->setTaille($taille)->setTailleManuelle($taille !== null);
            $this->em->flush();
            return;
        }

        $besoin->setTaille($taille)->setTailleManuelle($taille !== null);
        $this->em->flush();
    }

    /**
     * Rejoue le mouvement de sortie d'un besoin déjà donné à une nouvelle taille :
     * supprime l'ancien (le stock de l'ancienne taille est restitué) et en crée un nouveau.
     */
    private function rejoueMouvementTaille(DotationBesoin $besoin, ?string $taille, ?User $user): void
    {
        $ancien = $besoin->getMouvementSortie();
        $besoin->setMouvementSortie(null);
        if ($ancien !== null) {
            $this->em->remove($ancien);
        }

        $note = 'Dotation' . ($taille !== null ? ' — taille ' . $taille : '');
        $movement = $this->stockService->recordMovement(
            $besoin->getStockItem(),
            $besoin->getQuantite(),
            StockMovementType::SORTIE,
            StockMovementSource::DOTATION,
            $user,
            $note,
            taille: $taille,
        );
        $movement->setLicencie($besoin->getLicencie());
        $movement->setDirigeant($besoin->getDirigeant());

        $besoin->setMouvementSortie($movement);
    }

    /** Marque un besoin comme remis : crée le mouvement de sortie et passe le statut à « donné ». */
    public function markGiven(DotationBesoin $besoin, ?User $user): void
    {
        if ($besoin->getStatut() === DotationBesoinStatut::DONNE) {
            return;
        }

        $note = 'Dotation' . ($besoin->getTaille() !== null ? ' — taille ' . $besoin->getTaille() : '');

        $movement = $this->stockService->recordMovement(
            $besoin->getStockItem(),
            $besoin->getQuantite(),
            StockMovementType::SORTIE,
            StockMovementSource::DOTATION,
            $user,
            $note,
            taille: $besoin->getTaille(),
        );

        $movement->setLicencie($besoin->getLicencie());
        $movement->setDirigeant($besoin->getDirigeant());

        $besoin->setStatut(DotationBesoinStatut::DONNE)->setMouvementSortie($movement);
        $this->em->flush();
    }

    /** Annule une remise : repasse le besoin à « à donner » et supprime le mouvement de sortie. */
    public function cancelGiven(DotationBesoin $besoin): void
    {
        if ($besoin->getStatut() !== DotationBesoinStatut::DONNE) {
            return;
        }

        $movement = $besoin->getMouvementSortie();
        $besoin->setStatut(DotationBesoinStatut::A_DONNER)->setMouvementSortie(null);

        if ($movement !== null) {
            $this->em->remove($movement);
        }

        $this->em->flush();
    }
}
