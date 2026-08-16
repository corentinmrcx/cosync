<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\DotationAvancement;
use App\DTO\DotationSuiviGroupe;
use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\DotationAvancementStatut;
use App\Enum\DotationBesoinStatut;
use App\Repository\DotationBesoinRepository;
use App\Service\Stock\StockTailleResolver;

/**
 * Met en forme les besoins de dotation pour les écrans d'administration.
 * Lecture seule : rien n'est écrit ici.
 */
final class DotationSuiviPresenter
{
    public function __construct(
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly DotationResolver $resolver,
        private readonly StockTailleResolver $tailles,
    ) {}

    /**
     * Avancement de la dotation d'un licencié, pour le badge de sa fiche.
     * Null si aucun kit ne le concerne.
     */
    public function avancementDe(Licencie $licencie): ?DotationAvancement
    {
        $seasonId = $licencie->getSeason()->getId();
        $besoins = array_filter(
            $this->besoinRepository->findForLicencie($licencie),
            static fn (DotationBesoin $besoin): bool => $besoin->getSeason()->getId() === $seasonId,
        );

        // Besoins pas encore matérialisés (licencié non validé) : on regarde si un kit s'applique.
        if ($besoins === []) {
            return $this->resolver->resolveDotation($licencie) !== []
                ? new DotationAvancement(DotationAvancementStatut::A_PREPARER, 0, 0)
                : null;
        }

        $total = count($besoins);
        $donnes = count(array_filter($besoins, $this->estDonne(...)));

        return new DotationAvancement($this->statutPour($donnes, $total), $donnes, $total);
    }

    /**
     * Besoins encore à donner qui portent un texte de flocage : la liste à transmettre au
     * floqueur. Triée par personne, comme le suivi.
     *
     * @return list<DotationBesoin>
     */
    public function flocages(Season $season): array
    {
        return array_values(array_filter(
            $this->besoinRepository->findBySeason($season),
            static fn (DotationBesoin $besoin): bool => $besoin->getPersonnalisation() !== null
                && $besoin->getStatut() === DotationBesoinStatut::A_DONNER,
        ));
    }

    /**
     * Besoins de la saison regroupés par équipe. Dans chaque équipe, les personnes sont triées
     * par nom (tri du repository), mais celles entièrement servies passent en fin de liste, et
     * chez une personne partiellement servie les lignes déjà remises passent sous les autres :
     * l'écran sert à préparer ce qui reste à remettre.
     *
     * @return list<DotationSuiviGroupe>
     */
    public function groupesDeSuivi(Season $season): array
    {
        $groupes = [];

        foreach ($this->besoinsParEquipeEtPersonne($season) as $equipe => $personnes) {
            $ordonnes = $this->aplatir($this->personnesNonServiesDAbord(
                array_map($this->remisesEnFin(...), $personnes),
            ));

            $groupes[] = new DotationSuiviGroupe(
                (string) $equipe,
                $ordonnes,
                count($ordonnes),
                count(array_filter($ordonnes, fn (DotationBesoin $besoin): bool => !$this->estDonne($besoin))),
            );
        }

        return $groupes;
    }

    /**
     * Tailles proposées à la correction d'un besoin, article par article : une paire de
     * chaussettes se corrige en pointures, un maillot en tailles de vêtement.
     *
     * @param list<DotationSuiviGroupe> $groupes
     *
     * @return array<int, list<string>>
     */
    public function taillesParBesoin(array $groupes): array
    {
        $out = [];

        foreach ($groupes as $groupe) {
            foreach ($groupe->besoins as $besoin) {
                // Options de l'article servi : une ligne couverte par un écoulement se corrige
                // dans les déclinaisons de CE carton-là, pas dans celles du kit.
                $out[$besoin->getId()] = $this->tailles->options($besoin->getArticleServi());
            }
        }

        return $out;
    }

    /** @return array<string, array<string, list<DotationBesoin>>> */
    private function besoinsParEquipeEtPersonne(Season $season): array
    {
        $parEquipe = [];

        foreach ($this->besoinRepository->findBySeason($season) as $besoin) {
            $equipe = $besoin->getTeamName() ?? 'Sans équipe';
            $personne = $besoin->getLicencie() ?? $besoin->getDirigeant();
            $parEquipe[$equipe][$personne !== null ? (string) $personne->getUuid() : 'inconnu'][] = $besoin;
        }

        ksort($parEquipe);

        return $parEquipe;
    }

    /**
     * @param array<string, list<DotationBesoin>> $personnes
     *
     * @return list<list<DotationBesoin>>
     */
    private function personnesNonServiesDAbord(array $personnes): array
    {
        $aServir = [];
        $servies = [];

        foreach ($personnes as $besoins) {
            if ($this->tousDonnes($besoins)) {
                $servies[] = $besoins;
            } else {
                $aServir[] = $besoins;
            }
        }

        return [...$aServir, ...$servies];
    }

    /**
     * Lignes d'une même personne : ce qui reste à remettre d'abord, ce qui est déjà remis à la
     * fin. Le tri de PHP étant stable, l'ordre du repository survit à l'intérieur de chaque bloc.
     *
     * @param list<DotationBesoin> $besoins
     *
     * @return list<DotationBesoin>
     */
    private function remisesEnFin(array $besoins): array
    {
        usort(
            $besoins,
            fn (DotationBesoin $a, DotationBesoin $b): int => $this->estDonne($a) <=> $this->estDonne($b),
        );

        return $besoins;
    }

    /**
     * @param list<list<DotationBesoin>> $parPersonne
     *
     * @return list<DotationBesoin>
     */
    private function aplatir(array $parPersonne): array
    {
        return array_merge(...$parPersonne ?: [[]]);
    }

    /** @param list<DotationBesoin> $besoins */
    private function tousDonnes(array $besoins): bool
    {
        foreach ($besoins as $besoin) {
            if (!$this->estDonne($besoin)) {
                return false;
            }
        }

        return true;
    }

    private function estDonne(DotationBesoin $besoin): bool
    {
        return $besoin->getStatut() === DotationBesoinStatut::DONNE;
    }

    private function statutPour(int $donnes, int $total): DotationAvancementStatut
    {
        return match (true) {
            $donnes === $total => DotationAvancementStatut::REMISE,
            $donnes === 0 => DotationAvancementStatut::ATTENTE,
            default => DotationAvancementStatut::PARTIELLE,
        };
    }
}
