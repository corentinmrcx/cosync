<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\DotationFlocageReglages;
use App\DTO\DotationLigneCorrections;
use App\DTO\DotationSuiviGroupe;
use App\Entity\DotationBesoin;
use App\Entity\StockItem;
use App\Enum\DotationLigneCorrection;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Quelles corrections une ligne du suivi propose-t-elle dans son menu « ⋯ » ?
 *
 * Quatre crayons s'écrivaient contre chaque valeur de la ligne ; resté seul sous une marque, l'un
 * d'eux ne disait plus ce qu'il corrigeait. Les corrections n'ayant pas d'ordre, elles vont au
 * menu — et la règle qui dit laquelle s'offre vit ici, pas recopiée entre le menu et les
 * formulaires : c'est l'état de la ligne et les droits du compte qui en décident.
 */
final class DotationLigneCorrectionsResolver
{
    public function __construct(private readonly Security $security) {}

    /**
     * @param int  $options   options du groupe de choix proposées à cette personne
     * @param int  $articles  articles qui peuvent servir la ligne — celui du kit et ses écoulements
     * @param bool $floquable l'article se floque dans ce kit
     */
    public function pour(DotationBesoin $besoin, int $options, int $articles, bool $floquable): DotationLigneCorrections
    {
        // Le sac est fait : le carton est ouvert et le flocage commandé, on ne rechoisit plus.
        $ouverte = !$besoin->getStatut()->contenuFige();

        $gestes = [];
        if ($ouverte && $options > 1) {
            $gestes[] = DotationLigneCorrection::OPTION;
        }
        if ($ouverte && $articles > 1) {
            $gestes[] = DotationLigneCorrection::ARTICLE;
        }
        // La taille se corrige à tout statut : après une remise, le mouvement de stock est rejoué.
        $gestes[] = DotationLigneCorrection::TAILLE;
        if ($ouverte && $floquable) {
            $gestes[] = DotationLigneCorrection::FLOCAGE;
        }

        return new DotationLigneCorrections(array_values(array_filter(
            $gestes,
            fn (DotationLigneCorrection $geste): bool => $this->security->isGranted($geste->permission()->value),
        )));
    }

    /**
     * @param list<DotationSuiviGroupe>                $groupes
     * @param array<int, list<StockItem>>              $optionsParBesoin
     * @param array<int, list<StockItem>>              $articlesParBesoin
     * @param array<int, ?DotationFlocageReglages>     $flocagesParBesoin
     *
     * @return array<int, DotationLigneCorrections>
     */
    public function parBesoin(array $groupes, array $optionsParBesoin, array $articlesParBesoin, array $flocagesParBesoin): array
    {
        $out = [];

        foreach ($groupes as $groupe) {
            foreach ($groupe->besoins as $besoin) {
                $id = $besoin->getId();
                $out[$id] = $this->pour(
                    $besoin,
                    count($optionsParBesoin[$id] ?? []),
                    count($articlesParBesoin[$id] ?? []),
                    ($flocagesParBesoin[$id] ?? null) !== null,
                );
            }
        }

        return $out;
    }
}
