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
    /** L'encadrement forme son propre groupe, quelle que soit l'équipe dont il s'occupe. */
    private const GROUPE_DIRIGEANTS = 'Dirigeants';

    /** Des joueurs qu'aucune équipe n'a encore accueillis — un oubli d'affectation à traiter. */
    private const GROUPE_SANS_EQUIPE = 'Sans équipe';

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
     * Besoins de la saison regroupés par équipe, l'encadrement à part. Dans chaque groupe, les
     * personnes sont triées par nom (tri du repository), mais celles entièrement servies passent
     * en fin de liste, et chez une personne partiellement servie les lignes déjà remises passent
     * sous les autres : l'écran sert à préparer ce qui reste à remettre.
     *
     * @return list<DotationSuiviGroupe>
     */
    public function groupesDeSuivi(Season $season): array
    {
        $groupes = [];

        foreach ($this->besoinsParGroupeEtPersonne($season) as $equipe => $personnes) {
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

    /**
     * Regroupement de l'écran : une équipe de joueurs, ou l'encadrement.
     *
     * L'équipe d'un dirigeant est une indication interne — « il s'occupe des Séniors » — qui ne
     * dit rien de son kit : la mêler aux joueurs de cette équipe donnait deux dotations sans
     * rapport dans le même tableau, et renvoyait le reste de l'encadrement dans un « Sans équipe »
     * qu'on lisait comme un oubli d'affectation. Une personne qui est à la fois joueuse et
     * dirigeante tient donc deux blocs de lignes, un par titre — c'est bien deux kits qu'elle reçoit.
     *
     * @return array<string, array<string, list<DotationBesoin>>>
     */
    private function besoinsParGroupeEtPersonne(Season $season): array
    {
        $parGroupe = [];

        foreach ($this->besoinRepository->findBySeason($season) as $besoin) {
            $personne = $besoin->getLicencie() ?? $besoin->getDirigeant();
            $groupe = $besoin->getDirigeant() !== null
                ? self::GROUPE_DIRIGEANTS
                : $besoin->getTeamName() ?? self::GROUPE_SANS_EQUIPE;

            $parGroupe[$groupe][$personne !== null ? (string) $personne->getUuid() : 'inconnu'][] = $besoin;
        }

        return $this->ordonnerGroupes($parGroupe);
    }

    /**
     * Équipes par ordre alphabétique, puis les deux groupes fourre-tout en fin de liste : ce
     * sont les seuls que l'admin ne prépare pas équipe par équipe.
     *
     * @param  array<string, array<string, list<DotationBesoin>>> $parGroupe
     * @return array<string, array<string, list<DotationBesoin>>>
     */
    private function ordonnerGroupes(array $parGroupe): array
    {
        $fin = [];

        foreach ([self::GROUPE_SANS_EQUIPE, self::GROUPE_DIRIGEANTS] as $nom) {
            if (isset($parGroupe[$nom])) {
                $fin[$nom] = $parGroupe[$nom];
                unset($parGroupe[$nom]);
            }
        }

        ksort($parGroupe);

        return [...$parGroupe, ...$fin];
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
