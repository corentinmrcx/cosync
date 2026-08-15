<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\GrilleTaille;
use App\Entity\GrilleTailleValeur;
use App\Entity\StockItem;
use App\Entity\Taille;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockTailleProfil;
use App\Enum\TailleType;
use App\Repository\TailleRepository;
use App\Service\Referentiel\TailleReferentiel;
use App\Service\Stock\StockTailleResolver;
use PHPUnit\Framework\TestCase;

final class StockTailleResolverTest extends TestCase
{
    /** @var list<string> */
    private const VETEMENTS = ['S', 'M', 'XL', '128'];

    /** @var list<string> */
    private const POINTURES = ['42', '43', '44', '43-46'];

    private StockTailleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new StockTailleResolver(new TailleReferentiel($this->repositoryStub()));
    }

    public function testUnVetementSeDeclineEnTaillesDeVetement(): void
    {
        $maillot = $this->item(StockItemKind::EQUIPEMENT, StockItemVetementType::HAUT);

        self::assertSame(StockTailleProfil::VETEMENT, $this->resolver->profil($maillot));
        self::assertSame(self::VETEMENTS, $this->resolver->options($maillot), 'Le stock voit aussi les étiquetages fournisseur.');
    }

    public function testUnArticleChausseSeDeclineEnPointures(): void
    {
        // Le cas des chaussettes : proposer « XL » n'a aucun sens au réassort.
        $chaussettes = $this->item(StockItemKind::EQUIPEMENT, StockItemVetementType::CHAUSSURES);

        self::assertSame(StockTailleProfil::POINTURE, $this->resolver->profil($chaussettes));
        self::assertSame(self::POINTURES, $this->resolver->options($chaussettes));
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

    public function testUneGrilleRestreintLesDeclinaisonsACellesQueLeFournisseurVend(): void
    {
        $chaussettes = $this->chaussettesAvecGrille();

        self::assertSame(
            ['43-46'],
            $this->resolver->options($chaussettes),
            'Un article vendu en plages ne se range que sous ses plages, pas sous tout le référentiel.',
        );
    }

    public function testUneGrilleTraduitLaTailleDeclareeVersLeLibelleDuFournisseur(): void
    {
        $chaussettes = $this->chaussettesAvecGrille();

        self::assertSame('43-46', $this->resolver->traduire($chaussettes, '44'));
        self::assertSame('43-46', $this->resolver->traduire($chaussettes, '43'));
    }

    public function testUneTailleQueLaGrilleNeCouvrePasNeSeTraduitPas(): void
    {
        // Mieux vaut pas de taille qu'une taille fausse : le suivi affichera « à renseigner ».
        self::assertNull($this->resolver->traduire($this->chaussettesAvecGrille(), '42'));
    }

    public function testSansGrilleLaTailleDeclareePasseTelleQuelle(): void
    {
        $maillot = $this->item(StockItemKind::EQUIPEMENT, StockItemVetementType::HAUT);

        self::assertSame('M', $this->resolver->traduire($maillot, 'M'));
        self::assertNull($this->resolver->traduire($maillot, null));
    }

    public function testLesTaillesDejaEnStockRestentProposeesMalgreLaGrille(): void
    {
        // Stock saisi avant l'attribution de la grille : il faut pouvoir le sortir.
        $options = $this->resolver->options($this->chaussettesAvecGrille(), ['44']);

        self::assertContains('44', $options);
        self::assertContains('43-46', $options);
    }

    private function item(StockItemKind $kind, ?StockItemVetementType $type): StockItem
    {
        return (new StockItem())->setNom('Article')->setKind($kind)->setTypeVetement($type);
    }

    /** Des chaussettes vendues en « 43-46 », qui couvre les pointures 43 et 44. */
    private function chaussettesAvecGrille(): StockItem
    {
        $pointure = static fn (string $libelle): Taille => (new Taille())
            ->setLibelle($libelle)
            ->setType(TailleType::POINTURE);

        $valeur = (new GrilleTailleValeur())->setCible($pointure('43-46'));
        $valeur->addCouverture($pointure('43'));
        $valeur->addCouverture($pointure('44'));

        $grille = (new GrilleTaille())->setNom('Chaussettes Nike')->setType(TailleType::POINTURE);
        $grille->addValeur($valeur);

        return $this->item(StockItemKind::EQUIPEMENT, StockItemVetementType::CHAUSSURES)
            ->setGrilleTaille($grille);
    }

    /** Référentiel réduit : le résolveur choisit une échelle, il n'invente pas de valeurs. */
    private function repositoryStub(): TailleRepository
    {
        $ligne = static fn (string $libelle, TailleType $type): Taille => (new Taille())
            ->setLibelle($libelle)
            ->setType($type);

        $repository = $this->createStub(TailleRepository::class);
        $repository->method('findAllOrdered')->willReturn([
            ...array_map(static fn (string $t): Taille => $ligne($t, TailleType::VETEMENT), self::VETEMENTS),
            ...array_map(static fn (string $t): Taille => $ligne($t, TailleType::POINTURE), self::POINTURES),
        ]);

        return $repository;
    }
}
