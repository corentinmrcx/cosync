<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\DotationAvancement;
use App\DTO\DotationLigneTaille;
use App\DTO\DotationSuiviGroupe;
use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Enum\DotationAvancementStatut;
use App\Enum\DotationBesoinStatut;
use App\Enum\StockItemVetementType;
use App\Repository\DotationBesoinRepository;
use App\Repository\StockItemRepository;
use App\Service\Stock\StockTaillePresenter;
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
        private readonly StockItemRepository $itemRepository,
        private readonly DotationResolver $resolver,
        private readonly StockTailleResolver $tailles,
        private readonly StockTaillePresenter $etiquettes,
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
                ? new DotationAvancement(DotationAvancementStatut::PREVUE, 0, 0, 0)
                : null;
        }

        $total = count($besoins);
        $donnes = count(array_filter($besoins, $this->estDonne(...)));
        $prepares = count(array_filter($besoins, $this->estPrepare(...)));

        return new DotationAvancement($this->statutPour($donnes, $prepares, $total), $donnes, $prepares, $total);
    }

    /**
     * Besoins encore à donner qui portent un texte de flocage : la liste à transmettre au
     * floqueur. Triée par personne, comme le suivi.
     *
     * Une ligne préparée en sort, au même titre qu'une ligne remise : le flocage se commande
     * bien avant que le sac ne se fasse, la laisser dans la liste la ferait floquer deux fois.
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
     * en fin de liste, et chez une personne les lignes remontent dans l'ordre du parcours — à
     * donner, préparé, donné : l'écran sert à préparer ce qui reste à remettre.
     *
     * @return list<DotationSuiviGroupe>
     */
    public function groupesDeSuivi(Season $season): array
    {
        $groupes = [];

        foreach ($this->besoinsParGroupeEtPersonne($season) as $equipe => $personnes) {
            $ordonnes = $this->aplatir($this->personnesNonServiesDAbord(
                array_map($this->parAvancement(...), $personnes),
            ));

            $groupes[] = new DotationSuiviGroupe(
                (string) $equipe,
                $ordonnes,
                count($ordonnes),
                count(array_filter($ordonnes, fn (DotationBesoin $besoin): bool => !$this->estDonne($besoin))),
                count(array_filter($ordonnes, $this->estPrepare(...))),
            );
        }

        return $groupes;
    }

    /**
     * La taille de chaque ligne telle qu'elle se lit devant les cartons : l'étiquette du
     * carton, ce que la personne a déclaré quand l'étiquette ne le dit pas, et les
     * déclinaisons proposées à la correction — en pointures pour des chaussettes, en tailles
     * de vêtement pour un maillot.
     *
     * @param list<DotationSuiviGroupe> $groupes
     *
     * @return array<int, DotationLigneTaille>
     */
    public function taillesParBesoin(array $groupes): array
    {
        $out = [];

        foreach ($groupes as $groupe) {
            foreach ($groupe->besoins as $besoin) {
                $out[$besoin->getId()] = $this->ligneTaille($besoin);
            }
        }

        return $out;
    }

    /**
     * Le catalogue proposé quand on déclare avoir remis autre chose que ce que le kit prévoit.
     *
     * Les archivés compris : un article sorti du catalogue est justement celui qui traîne
     * encore dans l'armoire, et c'est bien celui-là qu'on attrape un soir de match.
     *
     * Une liste pour tout l'écran, pas une par ligne : le suivi en affiche des centaines, et
     * recopier le catalogue dans chacune pesait plus lourd que tout le reste de la page.
     *
     * @return list<array{id: int, label: string}>
     */
    public function articlesRemisables(): array
    {
        return array_map(
            static fn (StockItem $item): array => ['id' => $item->getId(), 'label' => $item->getDesignation()],
            $this->itemRepository->findAllOrdered(true),
        );
    }

    private function ligneTaille(DotationBesoin $besoin): DotationLigneTaille
    {
        // Article servi : une ligne couverte par un écoulement se lit et se corrige dans les
        // déclinaisons de CE carton-là, pas dans celles du kit.
        $article = $besoin->getArticleServi();
        $taille = $besoin->getTaille();
        $etiquette = $taille !== null ? $this->etiquettes->etiquette($article, $taille) : null;

        // Seule une ligne que le recalcul rattrape relit le dossier : relâcher la taille d'un
        // sac préparé ou remis la laisserait vide.
        $relachable = $besoin->getStatut()->suitLeRecalcul();

        return new DotationLigneTaille(
            $etiquette,
            $this->mentionDeclaree($besoin, $article, $etiquette),
            array_map(
                fn (string $valeur): array => ['valeur' => $valeur, 'libelle' => $this->etiquettes->etiquette($article, $valeur)],
                // La taille en place reste proposée même hors déclinaisons : rouvrir le
                // sélecteur ne doit pas la faire disparaître.
                $this->tailles->options($article, $taille !== null ? [$taille] : []),
            ),
            // Une taille qui suit le dossier ouvre le sélecteur sur « Automatique » : valider
            // sans rien toucher ne doit pas la verrouiller en douce.
            $relachable && !$besoin->isTailleManuelle() ? null : $taille,
            $relachable,
        );
    }

    /**
     * Ce que la personne a déclaré : « 37-40 » ne dit pas si le joueur chausse du 37 ou du 39, ni
     * « XS » qu'il a déclaré « 16 ans ».
     *
     * Pour un article chaussé, la pointure s'écrit **toujours** : un « 34 » seul ne dit pas s'il
     * est l'étiquette du carton ou la pointure du joueur — c'est cette confusion-là qui a fait
     * croire un 39 servi par le carton Nike 34-38. Un « M » sous un « M », lui, n'apprend rien.
     */
    private function mentionDeclaree(DotationBesoin $besoin, StockItem $article, ?string $etiquette): ?string
    {
        $personne = $besoin->getLicencie() ?? $besoin->getDirigeant();
        $declaree = $personne !== null ? $this->resolver->tailleDeclaree($personne, $article) : null;

        if ($declaree === null || $declaree === '') {
            return null;
        }

        if ($article->getTypeVetement() === StockItemVetementType::CHAUSSURES) {
            return 'pointure ' . $declaree;
        }

        return $declaree !== $etiquette ? 'taille ' . $declaree : null;
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
     * Lignes d'une même personne, dans l'ordre du parcours : ce qui reste à sortir du carton,
     * puis ce qui est préparé, puis ce qui est parti. Le tri de PHP étant stable, l'ordre du
     * repository survit à l'intérieur de chaque bloc.
     *
     * @param list<DotationBesoin> $besoins
     *
     * @return list<DotationBesoin>
     */
    private function parAvancement(array $besoins): array
    {
        usort(
            $besoins,
            fn (DotationBesoin $a, DotationBesoin $b): int => $this->rang($a) <=> $this->rang($b),
        );

        return $besoins;
    }

    /** Rang de la ligne dans le parcours : à donner, préparé, donné. */
    private function rang(DotationBesoin $besoin): int
    {
        return match ($besoin->getStatut()) {
            DotationBesoinStatut::A_DONNER => 0,
            DotationBesoinStatut::PREPARE => 1,
            DotationBesoinStatut::DONNE => 2,
        };
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
        return $besoin->getStatut()->estRemis();
    }

    private function estPrepare(DotationBesoin $besoin): bool
    {
        return $besoin->getStatut()->estPrepare();
    }

    /**
     * « Prête » ne se dit que d'une dotation entièrement mise de côté et pas encore entamée :
     * dès qu'une ligne est partie, c'est la remise qui intéresse le club, et le badge compte
     * les lignes remises plutôt que celles qui attendent.
     */
    private function statutPour(int $donnes, int $prepares, int $total): DotationAvancementStatut
    {
        return match (true) {
            $donnes === $total => DotationAvancementStatut::REMISE,
            $donnes > 0 => DotationAvancementStatut::PARTIELLE,
            $prepares === $total => DotationAvancementStatut::PREPAREE,
            default => DotationAvancementStatut::ATTENTE,
        };
    }
}
