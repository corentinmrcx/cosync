<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\DTO\Stock\AchatTaille;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementType;
use App\Service\Stock\AchatPresenter;

/**
 * L'écran des commandes se relit en face d'un devis : un article, ses tailles dessous, et
 * un cumul sur la ligne d'article — sans lui, personne ne sait combien de vestes le club
 * achète en tout.
 */
final class AchatPresenterTest extends StockIntegrationTestCase
{
    private function presenter(): AchatPresenter
    {
        return $this->service(AchatPresenter::class);
    }

    public function testLaLigneDArticleCumuleSesTailles(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT, $this->makeFournisseur('Alpha'));

        $this->makeBesoin($season, $veste, 'M', 3);
        $this->makeBesoin($season, $veste, 'S', 2);
        $this->makeMovement($veste, 1, StockMovementType::ENTREE, 'M');
        $this->em->flush();

        $fournisseurs = $this->presenter()->parFournisseur($season);

        self::assertCount(1, $fournisseurs);
        self::assertSame(4, $fournisseurs[0]->total, '2 en S + 2 en M restant à acheter.');

        // Le stock de la M est déjà déduit : l'écran n'annonce que ce qu'on commande.
        $article = $fournisseurs[0]->articles[0];
        self::assertSame('Veste', $article->article->getNom());
        self::assertTrue($article->detaille());
        self::assertNull($article->tailleUnique());
        self::assertSame(4, $article->quantite);

        self::assertSame(
            ['S', 'M'],
            array_map(static fn (AchatTaille $t): ?string => $t->etiquette, $article->tailles),
            'Les tailles suivent le référentiel, pas l\'ordre des besoins.',
        );
        self::assertSame(
            [2, 2],
            array_map(static fn (AchatTaille $t): int => $t->quantite, $article->tailles),
        );
    }

    public function testUneSeuleDeclinaisonNeSeRepetePas(): void
    {
        $season = $this->makeSeason();
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT, $this->makeFournisseur('Alpha'));

        $this->makeBesoin($season, $veste, 'L', 2);
        $this->em->flush();

        $article = $this->presenter()->parFournisseur($season)[0]->articles[0];

        self::assertFalse($article->detaille(), 'Le détail répéterait les nombres du dessus.');
        self::assertSame('L', $article->tailleUnique(), 'La taille reste lisible : c\'est ce qu\'on commande.');
    }

    public function testUnArticleSansTailleNAffichePasDeDeclinaison(): void
    {
        $season = $this->makeSeason();
        $sac = $this->makeItem('Sac', null, $this->makeFournisseur('Alpha'));

        $this->makeBesoin($season, $sac, null, 4);
        $this->em->flush();

        $article = $this->presenter()->parFournisseur($season)[0]->articles[0];

        self::assertFalse($article->detaille());
        self::assertNull($article->tailleUnique());
        self::assertSame(4, $article->quantite);
    }
}
