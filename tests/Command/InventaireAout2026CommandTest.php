<?php declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\StockCategory;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Reprise de l'inventaire d'août 2026. Ce que ces tests gardent :
 *
 * - la reprise est **rejouable** : c'est ce qui la rend sûre à relancer sur la prod, où l'on ne
 *   sait pas toujours si elle a déjà tourné ;
 * - elle **refuse de tourner à moitié** : sans les catégories ni l'auteur, les sous-requêtes
 *   rendraient NULL et l'on obtiendrait 87 articles sans catégorie et des entrées non signées,
 *   sans qu'aucune erreur ne le dise.
 *
 * Ils garantissent aussi, en creux, que la reprise ne pollue plus une base neuve : elle n'a lieu
 * que si quelqu'un lance la commande. C'est précisément ce que la migration ne savait pas faire.
 */
final class InventaireAout2026CommandTest extends KernelTestCase
{
    private const AUTEUR_EMAIL = 'corentinmarcoux51@gmail.com';

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testLaRepriseCreeLesArticlesEtLeursEntreesDeStock(): void
    {
        $this->preparerLeTerrain();

        $tester = $this->lancer();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Inventaire d\'août 2026 repris', $tester->getDisplay());

        self::assertSame(87, $this->compter('stock_item'));
        self::assertSame(95, $this->compter('stock_movement'));
    }

    public function testChaqueEntreeEstSigneeEtDateeDuJourDeLInventaire(): void
    {
        $this->preparerLeTerrain();
        $this->lancer();

        $orphelines = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM stock_movement WHERE created_by_id IS NULL',
        );
        self::assertSame(0, $orphelines, 'Une entrée d\'inventaire non signée est intraçable.');

        $horsDate = (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM stock_movement WHERE created_at <> TIMESTAMP '2026-08-31 12:00:00'",
        );
        self::assertSame(0, $horsDate, 'Les entrées portent la date de l\'inventaire, pas celle de la reprise.');

        $sansCategorie = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM stock_item WHERE category_id IS NULL',
        );
        self::assertSame(0, $sansCategorie, 'Un article sans catégorie est invisible là où le club le cherche.');
    }

    public function testRelancerLaRepriseNeCreeAucunDoublon(): void
    {
        $this->preparerLeTerrain();
        $this->lancer();

        $tester = $this->lancer();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('déjà repris', $tester->getDisplay());
        self::assertSame(87, $this->compter('stock_item'));
        self::assertSame(95, $this->compter('stock_movement'));
    }

    public function testUnArticleDejaGereEntreTempsNEstPasReecrit(): void
    {
        $this->preparerLeTerrain();
        $this->lancer();

        // Le club sort un ballon du stock après la reprise : la relance ne doit pas reposer
        // l'entrée d'inventaire par-dessus, sinon le stock remonterait tout seul.
        $this->em->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO stock_movement (quantite, taille, type, source, created_at, item_id, created_by_id)
                SELECT 1, NULL, 'sortie', 'manuel', NOW(), i.id, (SELECT id FROM "user" WHERE email = :email)
                FROM stock_item i WHERE i.nom = 'Sifflet'
                SQL,
            ['email' => self::AUTEUR_EMAIL],
        );

        $this->lancer();

        self::assertSame(96, $this->compter('stock_movement'), 'La relance ne repose pas une entrée déjà comptée.');
    }

    public function testSansLesCategoriesLaRepriseRefuseDEcrire(): void
    {
        $this->creerAuteur();

        $tester = $this->lancer();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Accessoire & Matériel', $tester->getDisplay());
        self::assertSame(0, $this->compter('stock_item'), 'Rien n\'est écrit tant que le terrain n\'est pas prêt.');
    }

    public function testSansLAuteurLaRepriseRefuseDEcrire(): void
    {
        $this->creerCategories();

        $tester = $this->lancer();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(self::AUTEUR_EMAIL, $tester->getDisplay());
        self::assertSame(0, $this->compter('stock_item'));
    }

    /* ── Utilitaires ── */

    private function preparerLeTerrain(): void
    {
        $this->creerCategories();
        $this->creerAuteur();
    }

    private function creerCategories(): void
    {
        foreach (['Accessoire & Matériel', 'Pharmacie'] as $position => $nom) {
            $categorie = (new StockCategory())->setName($nom)->setPosition($position);
            $this->em->persist($categorie);
        }

        $this->em->flush();
    }

    private function creerAuteur(): void
    {
        $user = (new User())
            ->setEmail(self::AUTEUR_EMAIL)
            ->setPassword('peu importe');

        $this->em->persist($user);
        $this->em->flush();
    }

    private function compter(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function lancer(): CommandTester
    {
        $commande = (new Application(self::$kernel))->find('app:inventaire:aout-2026');

        $tester = new CommandTester($commande);
        $tester->execute([]);

        return $tester;
    }
}
