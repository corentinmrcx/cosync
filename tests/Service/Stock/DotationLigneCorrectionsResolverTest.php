<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\DotationBesoin;
use App\Enum\DotationBesoinStatut;
use App\Enum\DotationLigneCorrection;
use App\Enum\Permission;
use App\Service\Dotation\DotationLigneCorrectionsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Quelles corrections le menu « ⋯ » d'une ligne du suivi propose-t-il ?
 *
 * Le menu et les formulaires lisent la même réponse : un article de menu sans formulaire
 * n'ouvrirait rien, un formulaire sans article de menu ne s'ouvrirait jamais.
 */
final class DotationLigneCorrectionsResolverTest extends TestCase
{
    public function testUneLigneAServirProposeCeQuElleSaitCorriger(): void
    {
        $corrections = $this->resolver()->pour($this->besoin(DotationBesoinStatut::A_DONNER), 2, 2, true);

        self::assertSame(
            [DotationLigneCorrection::OPTION, DotationLigneCorrection::ARTICLE, DotationLigneCorrection::TAILLE, DotationLigneCorrection::FLOCAGE],
            $corrections->gestes,
        );
        self::assertTrue($corrections->propose('taille'));
    }

    /** Pas de choix, pas d'écoulement, pas de flocage : il ne reste que la taille. */
    public function testUneLigneSansAlternativeNeCorrigeQueSaTaille(): void
    {
        $corrections = $this->resolver()->pour($this->besoin(DotationBesoinStatut::A_DONNER), 1, 1, false);

        self::assertSame([DotationLigneCorrection::TAILLE], $corrections->gestes);
        self::assertFalse($corrections->propose('article'));
    }

    /** Le sac est fait : on ne rechoisit plus le carton, mais une taille remise se corrige encore. */
    public function testUnSacFaitNeSeCorrigePlusQuEnTaille(): void
    {
        foreach ([DotationBesoinStatut::PREPARE, DotationBesoinStatut::DONNE] as $statut) {
            self::assertSame(
                [DotationLigneCorrection::TAILLE],
                $this->resolver()->pour($this->besoin($statut), 2, 2, true)->gestes,
            );
        }
    }

    /** Le bénévole qui remplit les sacs ne corrige pas les dotations : le menu ne s'affiche pas. */
    public function testUnComptePreparateurNaAucuneCorrection(): void
    {
        $corrections = $this->resolver(Permission::DOTATION_PREPARER)->pour($this->besoin(DotationBesoinStatut::A_DONNER), 2, 2, true);

        self::assertTrue($corrections->estVide());
    }

    private function resolver(Permission ...$accordees): DotationLigneCorrectionsResolver
    {
        $accordees = $accordees === [] ? [Permission::DOTATION_GERER] : $accordees;
        $valeurs = array_map(static fn (Permission $p): string => $p->value, $accordees);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $attribut): bool => in_array($attribut, $valeurs, true),
        );

        return new DotationLigneCorrectionsResolver($security);
    }

    private function besoin(DotationBesoinStatut $statut): DotationBesoin
    {
        return (new DotationBesoin())->setStatut($statut);
    }
}
