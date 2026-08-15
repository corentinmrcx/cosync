<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\StockItem;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockTailleProfil;
use App\Service\Referentiel\TailleReferentiel;

/**
 * Choisit les tailles proposées pour un article. Proposer partout la même liste fait ranger
 * un réassort de chaussettes en « XL » : la déclinaison suit le type de vêtement, et
 * l'épicerie n'en a aucune — sa contenance est portée par l'article lui-même.
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

        return [...$referentiel, ...array_values(array_diff($dejaUtilisees, $referentiel))];
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
