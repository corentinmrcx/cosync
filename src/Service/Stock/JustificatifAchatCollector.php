<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\DTO\Justificatif\JustificatifAchat;
use App\DTO\Justificatif\JustificatifArticle;
use App\DTO\Justificatif\JustificatifEffectif;
use App\DTO\Justificatif\JustificatifFournisseur;
use App\DTO\Justificatif\JustificatifKit;
use App\DTO\Justificatif\JustificatifKitEntree;
use App\DTO\Justificatif\JustificatifTaille;
use App\Entity\DotationAffectation;
use App\Entity\DotationBesoin;
use App\Entity\DotationModele;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Repository\DotationAffectationRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\DotationModeleRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;
use App\Service\Dotation\DotationResolver;

/**
 * Rassemble la pièce que le club présente avec un devis pour justifier une commande.
 *
 * Le club a déjà commandé 45 vestes pour en distribuer 30, et les quinze autres dorment
 * encore. Ce document existe pour que la question ne se pose plus : il montre, article par
 * article, **ce qu'il faut, ce qu'on a, et donc ce qu'on achète** — trois nombres, parce que
 * c'est le raisonnement entier de la personne qui signe.
 *
 * Tout le reste — l'ancienne référence qu'on écoule, le sac déjà préparé, une commande encore
 * en route — sert à obtenir ces trois nombres mais n'a pas à les encombrer. Ce sont des faits
 * d'inventaire : ils descendent en note, et seulement quand ils expliquent un écart.
 *
 * **Le calcul n'est pas refait.** Le « à commander » est celui
 * d'{@see AchatService::computeACommander()}, celui-là même qui alimente les bons de commande.
 * Un justificatif qui recalculerait de son côté finirait par annoncer un chiffre que le bon de
 * commande dément, et c'est exactement la confiance qu'il est censé rétablir. Ce service ne
 * fait qu'en dériver `onEnA = ilEnFaut - aCommander`, ce qui rend l'égalité vraie par
 * construction : la présidente ne peut pas tomber sur une ligne qui ne se vérifie pas.
 *
 * Lecture seule : rien n'est écrit ici. L'arbitrage de l'écoulement doit avoir eu lieu avant
 * l'appel, comme pour tout lecteur des achats.
 */
final class JustificatifAchatCollector
{
    /** L'encadrement forme son propre groupe — même découpage que le suivi des dotations. */
    private const GROUPE_DIRIGEANTS = 'Dirigeants';

    private const GROUPE_SANS_EQUIPE = 'Sans équipe';

    private const SANS_FOURNISSEUR = 'Sans fournisseur';

    public function __construct(
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly DotationModeleRepository $modeleRepository,
        private readonly DotationAffectationRepository $affectationRepository,
        private readonly DotationResolver $resolver,
        private readonly AchatService $achatService,
        private readonly StockMovementRepository $movementRepository,
        private readonly StockItemRepository $itemRepository,
        private readonly StockTaillePresenter $etiquettes,
    ) {}

    public function collecter(Season $season): JustificatifAchat
    {
        $besoins = $this->besoinRepository->findNonRemisBySeason($season);

        return new JustificatifAchat(
            $season->getLabel(),
            new \DateTimeImmutable(),
            $this->kits($season),
            $this->effectifs($season),
            $this->fournisseurs($season, $besoins),
            $this->compterPersonnes($season),
            $this->compterBesoin($besoins),
            $this->achatService->compterACommander($season),
            $this->compterRemis($season),
            $this->resteApresDistribution($besoins),
        );
    }

    // ── Le modèle ──

    /** @return list<JustificatifKit> */
    private function kits(Season $season): array
    {
        $personnes = $this->personnesParModele($season);
        $kits = [];

        foreach ($this->modeleRepository->findBySeason($season) as $modele) {
            // Un kit désactivé ne dote plus personne : le montrer laisserait croire à un
            // engagement que la commande ne couvre pas.
            if (!$modele->isActif()) {
                continue;
            }

            $kits[] = new JustificatifKit(
                $modele->getNom(),
                array_map(
                    // Le nom seul : sur un document sortant, « Dirigeant — » répété devant
                    // chaque personne noie la liste des destinataires.
                    static fn (DotationAffectation $a): string => $a->cibleNom(),
                    $this->affectationRepository->findByModele($modele),
                ),
                $this->entreesDuKit($modele),
                $personnes[$modele->getId()] ?? 0,
            );
        }

        return $kits;
    }

    /**
     * Les lignes du kit, les options d'un même choix rassemblées en une entrée.
     *
     * Deux lignes « 1 × Sac de sport » et « 1 × Sac à dos » du même groupe ne promettent
     * qu'un sac par personne. Alignées comme deux articles ordinaires, elles en promettaient
     * deux à la lecture, et le besoin d'en dessous paraissait deux fois trop petit.
     *
     * @return list<JustificatifKitEntree>
     */
    private function entreesDuKit(DotationModele $modele): array
    {
        $entrees = [];
        $groupes = [];

        foreach ($modele->getLignes() as $ligne) {
            $groupe = $ligne->getGroupeChoix();

            if ($groupe === null || !isset($groupes[$groupe])) {
                if ($groupe !== null) {
                    // La place de l'entrée est celle de la première option rencontrée :
                    // l'ordre du kit reste celui que l'admin a composé.
                    $groupes[$groupe] = count($entrees);
                }

                $entrees[] = new JustificatifKitEntree(
                    $ligne->getQuantite(),
                    [$ligne->getStockItem()],
                    $ligne->isObligatoire(),
                );

                continue;
            }

            $place = $groupes[$groupe];
            $entrees[$place] = new JustificatifKitEntree(
                $entrees[$place]->quantite,
                [...$entrees[$place]->options, $ligne->getStockItem()],
                $entrees[$place]->obligatoire,
            );
        }

        return $entrees;
    }

    // ── Ce qu'il faut, ce qu'on a, ce qu'on commande ──

    /**
     * Tous les articles de la dotation, groupés par fournisseur — pas seulement ceux à
     * commander.
     *
     * Un article dont il n'y a rien à acheter est le meilleur argument du document : « il en
     * faut 8, on en a 8, on n'achète rien » prouve que l'armoire a été regardée avant d'écrire
     * au fournisseur. Le taire laisserait croire qu'elle ne l'a pas été.
     *
     * @param list<DotationBesoin> $besoins
     *
     * @return list<JustificatifFournisseur>
     */
    private function fournisseurs(Season $season, array $besoins): array
    {
        $aCommander = $this->aCommanderParDeclinaison($season);
        $groupes = [];

        foreach ($this->besoinsParArticle($besoins) as $entree) {
            $article = $entree['article'];
            // Préfixe volontaire : PHP convertirait une clé « 12 » en entier, et le
            // regroupement par fournisseur perdrait son typage.
            $cle = 'fournisseur-' . ($article->getFournisseur()?->getId() ?? 'aucun');

            $groupes[$cle] ??= [
                'nom' => $article->getFournisseur()?->getNom() ?? self::SANS_FOURNISSEUR,
                'articles' => [],
                'total' => 0,
            ];

            $construit = $this->article($article, $entree['tailles'], $aCommander);
            $groupes[$cle]['articles'][] = $construit;
            $groupes[$cle]['total'] += $construit->aCommander;
        }

        return array_map(
            static fn (array $g): JustificatifFournisseur => new JustificatifFournisseur(
                $g['nom'],
                $g['articles'],
                $g['total'],
            ),
            array_values($groupes),
        );
    }

    /**
     * @param array<string, array{taille: ?string, besoin: int, ecoule: int, references: array<string, true>}> $tailles
     * @param array<string, array{aCommander: int, enAttente: int}>                                            $aCommander
     */
    private function article(StockItem $article, array $tailles, array $aCommander): JustificatifArticle
    {
        $ilEnFaut = 0;
        $commande = 0;
        $ecoule = 0;
        $enAttente = 0;
        $references = [];
        $detail = [];

        foreach ($tailles as $cle => $ligne) {
            $achat = $aCommander[$cle] ?? ['aCommander' => 0, 'enAttente' => 0];

            $ilEnFaut += $ligne['besoin'];
            $commande += $achat['aCommander'];
            $ecoule += $ligne['ecoule'];
            $enAttente += $achat['enAttente'];
            $references += $ligne['references'];

            $detail[] = new JustificatifTaille(
                $ligne['taille'] !== null ? $this->etiquettes->etiquette($article, $ligne['taille']) : '—',
                $ligne['besoin'],
                // Dérivé, jamais recalculé : l'égalité « il en faut - on en a = à commander »
                // est vraie par construction, à n'importe quelle ligne du document.
                $ligne['besoin'] - $achat['aCommander'],
                $achat['aCommander'],
            );
        }

        return new JustificatifArticle(
            $article,
            $ilEnFaut,
            $ilEnFaut - $commande,
            $commande,
            // Une seule déclinaison : la ligne d'article dit déjà tout, le détail ne ferait
            // que répéter les mêmes nombres une ligne plus bas.
            count($detail) > 1 ? $detail : [],
            $this->notes($ecoule, $references, $enAttente),
            $this->resteDe($article, $tailles),
        );
    }

    /**
     * Ce qui explique un écart, et rien d'autre. Une note qui se déclenche à chaque ligne
     * n'est plus lue ; celle qui n'apparaît que quand le compte surprend est lue à coup sûr.
     *
     * @param array<string, true> $references
     *
     * @return list<string>
     */
    private function notes(int $ecoule, array $references, int $enAttente): array
    {
        $notes = [];

        if ($ecoule > 0 && $references !== []) {
            $notes[] = sprintf(
                '%d servi%s par ce qui reste de %s, que le club écoule avant d\'acheter du neuf.',
                $ecoule,
                $ecoule > 1 ? 's' : '',
                implode(', ', array_keys($references)),
            );
        }

        if ($enAttente > 0) {
            $notes[] = sprintf(
                '%d déjà commandé%s et pas encore livré%s : autant de moins à commander aujourd\'hui.',
                $enAttente,
                $enAttente > 1 ? 's' : '',
                $enAttente > 1 ? 's' : '',
            );
        }

        return $notes;
    }

    /**
     * Ce qui restera de cet article en armoire une fois tout le monde servi.
     *
     * @param array<string, array{taille: ?string, besoin: int, ecoule: int, references: array<string, true>}> $tailles
     */
    private function resteDe(StockItem $article, array $tailles): int
    {
        $reste = 0;

        foreach ($this->movementRepository->getStockGroupedByTaille($article) as $taille => $quantite) {
            if ($quantite <= 0) {
                continue;
            }

            $cle = $this->cle($article, (string) $taille !== '' ? (string) $taille : null);
            $reste += max(0, $quantite - ($tailles[$cle]['besoin'] ?? 0));
        }

        return $reste;
    }

    /**
     * Besoins de la saison par article du kit, puis par taille.
     *
     * `ecoule` retient ce qu'une ancienne référence couvre : le calcul d'achat solde ces
     * unités sous l'article de substitution, où elles disparaissent, et sans elles la ligne
     * annoncerait un besoin amputé de ce que le lecteur trouve en multipliant l'effectif.
     *
     * @param list<DotationBesoin> $besoins
     *
     * @return list<array{article: StockItem, tailles: array<string, array{taille: ?string, besoin: int, ecoule: int, references: array<string, true>}>}>
     */
    private function besoinsParArticle(array $besoins): array
    {
        $parArticle = [];

        foreach ($besoins as $besoin) {
            $article = $besoin->getStockItem();
            $id = $article->getId();
            $cle = $this->cle($article, $besoin->getTaille());

            $parArticle[$id] ??= ['article' => $article, 'tailles' => []];
            $parArticle[$id]['tailles'][$cle] ??= [
                'taille' => $besoin->getTaille(),
                'besoin' => 0,
                'ecoule' => 0,
                'references' => [],
            ];
            $parArticle[$id]['tailles'][$cle]['besoin'] += $besoin->getQuantite();

            if ($besoin->estServiParEcoulement()) {
                $parArticle[$id]['tailles'][$cle]['ecoule'] += $besoin->getQuantite();
                $parArticle[$id]['tailles'][$cle]['references'][$besoin->getArticleServi()->getDesignation()] = true;
            }
        }

        return array_values($parArticle);
    }

    /**
     * Le « à commander » du bon de commande, indexé par déclinaison.
     *
     * @return array<string, array{aCommander: int, enAttente: int}>
     */
    private function aCommanderParDeclinaison(Season $season): array
    {
        $out = [];

        foreach ($this->achatService->computeACommander($season) as $groupe) {
            foreach ($groupe['lignes'] as $ligne) {
                $out[$this->cle($ligne['stockItem'], $ligne['taille'])] = [
                    'aCommander' => $ligne['aCommander'],
                    'enAttente' => $ligne['enAttente'],
                ];
            }
        }

        return $out;
    }

    /**
     * Ce qui restera en armoire, tous articles confondus — y compris les anciennes références
     * que plus aucun besoin ne consomme.
     *
     * C'est le chiffre qui fait la promesse du document : à zéro, tout ce qui est commandé
     * part chez un licencié. Au-dessus, il y a du stock qui ne trouve pas preneur, et mieux
     * vaut que le document le dise que de le laisser découvrir à l'inventaire.
     *
     * @param list<DotationBesoin> $besoins
     */
    private function resteApresDistribution(array $besoins): int
    {
        $pris = [];

        foreach ($besoins as $besoin) {
            $cle = $this->cle($besoin->getArticleServi(), $besoin->getTaille());
            $pris[$cle] = ($pris[$cle] ?? 0) + $besoin->getQuantite();
        }

        $reste = 0;

        foreach ($this->articlesConcernes($besoins) as $article) {
            foreach ($this->movementRepository->getStockGroupedByTaille($article) as $taille => $quantite) {
                if ($quantite <= 0) {
                    continue;
                }

                $cle = $this->cle($article, (string) $taille !== '' ? (string) $taille : null);
                $reste += max(0, $quantite - ($pris[$cle] ?? 0));
            }
        }

        return $reste;
    }

    /**
     * Articles des kits et les anciennes références qui les remplacent, sans doublon.
     *
     * Les substituts sont lus depuis le référentiel, pas depuis les besoins : un carton
     * d'ancienne marque que personne ne peut porter ne produit aucun besoin, et c'est pourtant
     * exactement le stock que le document doit avouer.
     *
     * @param list<DotationBesoin> $besoins
     *
     * @return list<StockItem>
     */
    private function articlesConcernes(array $besoins): array
    {
        $vus = [];
        $concernes = [];

        foreach ($besoins as $besoin) {
            $item = $besoin->getStockItem();

            foreach ([$item, ...$this->itemRepository->findSubstituts($item)] as $article) {
                if (!isset($vus[$article->getId()])) {
                    $vus[$article->getId()] = true;
                    $concernes[] = $article;
                }
            }
        }

        return $concernes;
    }

    // ── Qui est concerné ──

    /** @return list<JustificatifEffectif> */
    private function effectifs(Season $season): array
    {
        /** @var array<string, array{personnes: array<string, true>, kits: array<string, true>}> $parGroupe */
        $parGroupe = [];

        foreach ($this->besoinRepository->findBySeason($season) as $besoin) {
            $personne = $besoin->getLicencie() ?? $besoin->getDirigeant();
            if ($personne === null) {
                continue;
            }

            $groupe = $besoin->getDirigeant() !== null
                ? self::GROUPE_DIRIGEANTS
                : $besoin->getTeamName() ?? self::GROUPE_SANS_EQUIPE;

            $parGroupe[$groupe] ??= ['personnes' => [], 'kits' => []];
            $parGroupe[$groupe]['personnes'][(string) $personne->getUuid()] = true;

            // Un groupe sans kit résolu garde sa case vide : c'est un oubli d'affectation, et
            // le document doit le montrer plutôt que de taire des personnes non dotées.
            $modele = $this->resolver->resolveModele($personne);
            if ($modele !== null) {
                $parGroupe[$groupe]['kits'][$modele->getNom()] = true;
            }
        }

        $effectifs = [];
        foreach ($this->ordonnerGroupes($parGroupe) as $nom => $groupe) {
            $effectifs[] = new JustificatifEffectif(
                (string) $nom,
                count($groupe['personnes']),
                array_keys($groupe['kits']),
            );
        }

        return $effectifs;
    }

    /**
     * Équipes par ordre alphabétique, les deux groupes fourre-tout en fin de liste — même
     * ordre que le suivi, pour que les deux écrans se relisent l'un l'autre.
     *
     * @param array<string, array{personnes: array<string, true>, kits: array<string, true>}> $parGroupe
     *
     * @return array<string, array{personnes: array<string, true>, kits: array<string, true>}>
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

    // ── Comptes d'ensemble ──

    /** @param list<DotationBesoin> $besoins */
    private function compterBesoin(array $besoins): int
    {
        $total = 0;

        foreach ($besoins as $besoin) {
            $total += $besoin->getQuantite();
        }

        return $total;
    }

    /**
     * Ce qui est déjà parti chez les licenciés. Hors de tout calcul d'achat — la sortie de
     * stock est faite — mais le document le dit : sans lui, une commande passée en cours de
     * saison annoncerait un engagement rétréci.
     */
    private function compterRemis(Season $season): int
    {
        $total = 0;

        foreach ($this->besoinRepository->findBySeason($season) as $besoin) {
            if ($besoin->getStatut()->estRemis()) {
                $total += $besoin->getQuantite();
            }
        }

        return $total;
    }

    /** Personnes distinctes que la saison dote, remises comprises. */
    private function compterPersonnes(Season $season): int
    {
        $uuids = [];

        foreach ($this->besoinRepository->findBySeason($season) as $besoin) {
            $personne = $besoin->getLicencie() ?? $besoin->getDirigeant();
            if ($personne !== null) {
                $uuids[(string) $personne->getUuid()] = true;
            }
        }

        return count($uuids);
    }

    /** @return array<int, int> id de modèle → personnes distinctes */
    private function personnesParModele(Season $season): array
    {
        $vus = [];

        foreach ($this->besoinRepository->findBySeason($season) as $besoin) {
            $personne = $besoin->getLicencie() ?? $besoin->getDirigeant();
            if ($personne === null) {
                continue;
            }

            $modele = $this->resolver->resolveModele($personne);
            if ($modele !== null) {
                $vus[$modele->getId()][(string) $personne->getUuid()] = true;
            }
        }

        return array_map(count(...), $vus);
    }

    private function cle(StockItem $article, ?string $taille): string
    {
        return $article->getId() . '|' . ($taille ?? '');
    }
}
