<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\StockItem;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockTailleProfil;
use App\Service\Referentiel\TailleReferentiel;

/**
 * Autorité unique sur les tailles d'un article : celles qu'on peut lui saisir, et la
 * traduction de ce qu'une personne a déclaré vers ce que le fournisseur étiquette.
 *
 * Proposer partout la même liste fait ranger un réassort de chaussettes en « XL » : la
 * déclinaison suit le type de vêtement, l'épicerie n'en a aucune — sa contenance est portée
 * par l'article lui-même — et une grille, quand l'article en a une, restreint encore la liste
 * aux déclinaisons réellement vendues.
 */
final class StockTailleResolver
{
    public function __construct(
        private readonly TailleReferentiel $referentiel,
    ) {}

    public function profil(StockItem $item): StockTailleProfil
    {
        if ($item->getKind() === StockItemKind::EPICERIE) {
            return StockTailleProfil::AUCUNE;
        }

        return match ($item->getTypeVetement()) {
            StockItemVetementType::HAUT, StockItemVetementType::BAS => StockTailleProfil::VETEMENT,
            StockItemVetementType::CHAUSSURES => StockTailleProfil::POINTURE,
            null => StockTailleProfil::AUCUNE,
        };
    }

    /**
     * Options d'un article, complétées des tailles déjà présentes en stock : un article dont
     * le type a changé garde des déclinaisons hors référentiel, qu'il faut pouvoir sortir.
     *
     * @param list<string> $dejaUtilisees
     *
     * @return list<string>
     */
    public function options(StockItem $item, array $dejaUtilisees = []): array
    {
        $type = $this->profil($item)->type();
        // Le stock voit tout le référentiel du type, y compris les tailles que les
        // formulaires ne proposent pas : c'est là qu'on range les étiquettes fournisseur.
        $referentiel = $type === null ? [] : $this->referentiel->pourLeStock($type);

        // Une grille dit dans quelles déclinaisons ce fournisseur-là vend l'article : proposer
        // le référentiel entier ferait ranger un réassort de chaussettes « 43-46 » sous « 44 ».
        //
        // Elle n'écarte que ce qu'elle traduit : le « 10 ans » disparaît parce qu'il se range
        // en « 140 », mais le « L », qu'aucune ligne ne mentionne, reste une déclinaison
        // valable — c'est bien sous ce nom-là que le fournisseur le vend. Même règle que
        // `traduire()`, et il le faut : une taille servie par la dotation doit pouvoir se
        // saisir en mouvement de stock.
        //
        // Le filtre préserve l'ordre du référentiel, qui est celui de tous les sélecteurs.
        $grille = $item->getGrilleTaille();
        if ($grille !== null) {
            $traduites = $grille->libellesCouverts();
            $cibles = $grille->libellesCibles();
            $referentiel = array_values(array_filter(
                $referentiel,
                static fn (string $libelle): bool => in_array($libelle, $cibles, true)
                    || !in_array($libelle, $traduites, true),
            ));
        }

        return [...$referentiel, ...array_values(array_diff($dejaUtilisees, $referentiel))];
    }

    /**
     * Traduit une taille déclarée dans le vocabulaire de l'article.
     *
     * Sans grille, il n'y a rien à traduire : l'article se décline dans le vocabulaire déclaré
     * lui-même. Avec grille, la pointure 44 devient le « 43-46 » du carton — sans quoi la
     * dotation sortirait du stock une taille qui n'existe chez aucun fournisseur.
     *
     * **Une grille ne traduit que ce qu'elle mentionne.** Une taille qu'aucune ligne ne couvre
     * passe telle quelle : c'est le cas courant d'un fournisseur qui vend ses vestes enfant en
     * « 140 » et ses vestes adulte en « L » — seule la moitié enfant demande une traduction.
     * La version précédente rendait null, ce qui obligeait à écrire « L couvre L », « M couvre
     * M »… pour tout le reste du référentiel : une cérémonie qui n'apprenait rien à personne
     * et qu'on oubliait, envoyant alors chaque adulte en « à renseigner ».
     */
    public function traduire(StockItem $item, ?string $tailleDeclaree): ?string
    {
        if ($tailleDeclaree === null || $tailleDeclaree === '') {
            return null;
        }

        $grille = $item->getGrilleTaille();

        return $grille?->cibleQuiCouvre($tailleDeclaree) ?? $tailleDeclaree;
    }

    /**
     * Vrai si la taille appartient à l'article. Les écrans ne proposent déjà que les bonnes,
     * mais une soumission forgée irait sinon ranger un réassort de chaussettes sous « XL ».
     *
     * @param list<string> $dejaUtilisees
     */
    public function estAdmise(StockItem $item, string $taille, array $dejaUtilisees = []): bool
    {
        return in_array($taille, $this->options($item, $dejaUtilisees), true);
    }

    /**
     * Vrai pour un équipement dont le type de vêtement n'est pas renseigné : sans lui, ni la
     * modale de mouvement ni la dotation ne savent quelle liste de tailles proposer.
     */
    public function typeVetementARenseigner(StockItem $item): bool
    {
        return $item->getKind() !== StockItemKind::EPICERIE && $item->getTypeVetement() === null;
    }
}
