<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\StockItem;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockTailleProfil;
use App\Service\Referentiel\Tailles;
use App\Service\Stock\StockTailleResolver;
use PHPUnit\Framework\TestCase;

final class StockTailleResolverTest extends TestCase
{
    private StockTailleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new StockTailleResolver();
    }

    public function testUnVetementSeDeclineEnTaillesDeVetement(): void
    {
        $maillot = $this->item(StockItemKind::EQUIPEMENT, StockItemVetementType::HAUT);

        self::assertSame(StockTailleProfil::VETEMENT, $this->resolver->profil($maillot));
        self::assertSame(Tailles::toutes(), $this->resolver->options($maillot));
    }

    public function testUnArticleChausseSeDeclineEnPointures(): void
    {
        // Le cas des chaussettes : proposer « XL » n'a aucun sens au réassort.
        $chaussettes = $this->item(StockItemKind::EQUIPEMENT, StockItemVetementType::CHAUSSURES);

        self::assertSame(StockTailleProfil::POINTURE, $this->resolver->profil($chaussettes));
        self::assertSame(Tailles::pointures(), $this->resolver->options($chaussettes));
        self::assertNotContains('XL', $this->resolver->options($chaussettes));
    }

    public function testLEpicerieNaPasDeTaille(): void
    {
        $boisson = $this->item(StockItemKind::EPICERIE, null);

        self::assertSame(StockTailleProfil::AUCUNE, $this->resolver->profil($boisson));
        self::assertSame([], $this->resolver->options($boisson));
        self::assertFalse($this->resolver->typeVetementARenseigner($boisson));
    }

    public function testUnEquipementSansTypeDeVetementNeProposeRienEtInviteALeRenseigner(): void
    {
        $ballon = $this->item(StockItemKind::EQUIPEMENT, null);

        self::assertSame([], $this->resolver->options($ballon));
        self::assertTrue($this->resolver->typeVetementARenseigner($ballon));
    }

    public function testLesTaillesDejaEnStockRestentProposees(): void
    {
        // Article passé de « haut » à « pieds » : ses tailles historiques doivent pouvoir sortir.
        $chaussettes = $this->item(StockItemKind::EQUIPEMENT, StockItemVetementType::CHAUSSURES);

        $options = $this->resolver->options($chaussettes, ['42', 'XL']);

        self::assertContains('XL', $options);
        self::assertSame(
            1,
            count(array_keys($options, '42', true)),
            'Une taille déjà au référentiel ne doit pas être doublée.',
        );
    }

    private function item(StockItemKind $kind, ?StockItemVetementType $type): StockItem
    {
        return (new StockItem())->setNom('Article')->setKind($kind)->setTypeVetement($type);
    }
}
