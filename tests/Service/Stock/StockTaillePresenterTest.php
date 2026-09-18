<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\GrilleTaille;
use App\Entity\GrilleTailleValeur;
use App\Entity\StockItem;
use App\Entity\Taille;
use App\Enum\StockItemVetementType;
use App\Enum\TailleType;
use App\Service\Stock\StockTaillePresenter;
use PHPUnit\Framework\TestCase;

final class StockTaillePresenterTest extends TestCase
{
    private StockTaillePresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new StockTaillePresenter();
    }

    /**
     * Le cas qui a trompé le club : le « 37 » de l'Erima couvre les pointures 37 à 40. Affiché
     * seul, il faisait passer un joueur qui chausse du 39 pour un 37.
     */
    public function testUnLibelleQuiCouvreUnePlageDePointuresLaDeplie(): void
    {
        $chaussettes = $this->article(TailleType::POINTURE, ['37' => ['39', '37', '40', '38']]);

        self::assertSame('37-40', $this->presenter->etiquette($chaussettes, '37'));
    }

    public function testUnePlageTroueeSEnumere(): void
    {
        $chaussettes = $this->article(TailleType::POINTURE, ['33' => ['33', '35']]);

        self::assertSame('33, 35', $this->presenter->etiquette($chaussettes, '33'));
    }

    /** « XS » pour « 16 ans » : le libellé du vêtement est déjà celui du carton. */
    public function testUnLibelleDeVetementResteTelQuel(): void
    {
        $veste = $this->article(TailleType::VETEMENT, ['XS' => ['16 ans'], '140' => ['10 ans']]);

        self::assertSame('XS', $this->presenter->etiquette($veste, 'XS'));
        self::assertSame('140', $this->presenter->etiquette($veste, '140'));
    }

    public function testUneTailleQueLaGrilleNeMentionnePasResteTelleQuelle(): void
    {
        $chaussettes = $this->article(TailleType::POINTURE, ['37' => ['37', '38']]);

        self::assertSame('42', $this->presenter->etiquette($chaussettes, '42'));
    }

    public function testSansGrilleLaTailleResteTelleQuelle(): void
    {
        $chaussettes = (new StockItem())->setNom('Chaussettes')->setTypeVetement(StockItemVetementType::CHAUSSURES);

        self::assertSame('37', $this->presenter->etiquette($chaussettes, '37'));
    }

    /** @param array<int|string, list<string>> $valeurs libellé du carton => tailles déclarées couvertes */
    private function article(TailleType $type, array $valeurs): StockItem
    {
        $taille = static fn (string $libelle): Taille => (new Taille())->setLibelle($libelle)->setType($type);

        $grille = (new GrilleTaille())->setNom('Grille')->setType($type);
        foreach ($valeurs as $cible => $couvertures) {
            $valeur = (new GrilleTailleValeur())->setCible($taille((string) $cible));
            foreach ($couvertures as $couverte) {
                $valeur->addCouverture($taille($couverte));
            }
            $grille->addValeur($valeur);
        }

        return (new StockItem())->setNom('Article')->setGrilleTaille($grille);
    }
}
