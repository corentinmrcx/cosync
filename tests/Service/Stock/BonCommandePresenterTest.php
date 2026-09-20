<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\DTO\Stock\AchatTaille;
use App\Entity\Commande;
use App\Entity\CommandeLigne;
use App\Entity\StockItem;
use App\Enum\CommandeStatut;
use App\Enum\StockItemVetementType;
use App\Service\Stock\BonCommandePresenter;

/**
 * Le bon qu'on a sous les yeux chez le fournisseur, puis en déballant les cartons.
 *
 * Les bons écrits avant le regroupement portent leurs lignes dans l'ordre des licenciés
 * rencontrés : le tri s'applique à l'affichage, pour qu'ils se relisent comme les autres.
 */
final class BonCommandePresenterTest extends StockIntegrationTestCase
{
    private function presenter(): BonCommandePresenter
    {
        return $this->service(BonCommandePresenter::class);
    }

    /** @param array<string, int> $lignes { taille (vide = aucune): quantité } */
    private function makeCommande(StockItem $item, array $lignes): Commande
    {
        $commande = (new Commande())
            ->setSeason($this->makeSeason())
            ->setStatut(CommandeStatut::BROUILLON);

        foreach ($lignes as $taille => $quantite) {
            $ligne = (new CommandeLigne())
                ->setStockItem($item)
                ->setTaille($taille === '' ? null : (string) $taille)
                ->setQuantite($quantite);
            $commande->addLigne($ligne);
            $this->em->persist($ligne);
        }

        $this->em->persist($commande);

        return $commande;
    }

    public function testUnArticleSesTaillesDessousDansLOrdreDuReferentiel(): void
    {
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        // Saisies dans le désordre, comme les licenciés arrivent.
        $commande = $this->makeCommande($veste, ['XL' => 1, 'S' => 2, 'M' => 3]);
        $this->em->flush();

        $articles = $this->presenter()->articles($commande);

        self::assertCount(1, $articles, 'Un seul carton, donc une seule ligne d\'article.');
        self::assertSame(6, $articles[0]->quantite);
        self::assertTrue($articles[0]->detaille());
        self::assertSame(
            ['S', 'M', 'XL'],
            array_map(static fn (AchatTaille $t): ?string => $t->etiquette, $articles[0]->tailles),
        );
    }

    public function testLesLignesAPlatSuiventLeMemeOrdre(): void
    {
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $commande = $this->makeCommande($veste, ['XL' => 1, 'S' => 2, 'M' => 3]);
        $this->em->flush();

        self::assertSame(
            ['S', 'M', 'XL'],
            array_map(
                static fn (CommandeLigne $l): ?string => $l->getTaille(),
                $this->presenter()->lignes($commande),
            ),
            'L\'écran de suivi se relit comme le PDF.',
        );
    }

    public function testUnArticleSansDeclinaisonNAPasDeDetail(): void
    {
        $sac = $this->makeItem('Sac', null);
        $commande = $this->makeCommande($sac, ['' => 4]);
        $this->em->flush();

        $article = $this->presenter()->articles($commande)[0];

        self::assertFalse($article->detaille());
        self::assertNull($article->tailleUnique());
        self::assertSame(4, $article->quantite);
    }
}
