<?php declare(strict_types=1);

namespace App\Twig;

use App\Entity\StockItem;
use App\Service\Stock\StockTaillePresenter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `taille_carton(article, taille)` — la déclinaison telle qu'elle est écrite sur le carton.
 *
 * Le libellé du stock, « 37 », se lit comme une pointure alors qu'il désigne le carton Erima
 * 37-40. Les écrans d'achat, la fiche d'une commande et le bon de commande PDF — ce qui part
 * chez le fournisseur — l'affichent donc déplié. La mise en forme reste celle de
 * {@see StockTaillePresenter}, seule autorité : le suivi des dotations la lit par son DTO.
 */
final class StockTailleExtension extends AbstractExtension
{
    public function __construct(
        private readonly StockTaillePresenter $etiquettes,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('taille_carton', $this->tailleCarton(...)),
        ];
    }

    public function tailleCarton(StockItem $item, string $taille): string
    {
        return $this->etiquettes->etiquette($item, $taille);
    }
}
