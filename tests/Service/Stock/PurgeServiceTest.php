<?php declare(strict_types=1);

namespace App\Tests\Service\Stock;

use App\Entity\AttestationCle;
use App\Entity\CleMouvement;
use App\Entity\Detenteur;
use App\Entity\Dirigeant;
use App\Entity\StockCategory;
use App\Enum\CleMouvementType;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementType;
use App\Service\Ops\PurgeService;
use App\Service\Pdf\PdfStorage;
use App\Tests\Support\DocumentFixtures;

final class PurgeServiceTest extends StockIntegrationTestCase
{
    private function rowCount(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    public function testPurgeVideToutesLesDonneesMaisGardeLesReferentiels(): void
    {
        // — Sème la chaîne complète, y compris dotations + commandes (ce qui faisait échouer l'ancienne purge) —
        $season = $this->makeSeason();
        $cat = $this->makeCategory();
        $team = $this->makeTeam($season);
        $four = $this->makeFournisseur();

        $sc = (new StockCategory())->setName('Maillots');
        $this->em->persist($sc);

        $item = $this->makeItem('Veste', StockItemVetementType::HAUT, $four);
        $item->setCategory($sc);

        $licencie = $this->makeLicencie($season, $cat, $team);

        $modele = $this->makeModele($season);
        $this->addLigne($modele, $item, 1);
        $this->affecterCategorie($season, $modele, $cat);
        $this->makeBesoin($season, $item, 'L', 1);

        $this->makeMovement($item, 5, StockMovementType::ENTREE, 'L');
        $this->makeCommandeEnAttente($season, $item, 'L', 3, $four);

        $dirigeant = (new Dirigeant())->setNom('DUPONT')->setPrenom('Thomas')->setSeason($season);
        $this->em->persist($dirigeant);

        // Registre des clés : le détenteur vit hors saison, l'attestation dans la saison.
        $detenteur = (new Detenteur())->setNom('DUPONT')->setPrenom('Thomas');
        $this->em->persist($detenteur);

        // Documents signables et signatures : ajoutés après l'écriture initiale de la purge,
        // ils référencent season, licencie et dirigeant — donc bloquent la purge s'ils sont oubliés.
        $documents = new DocumentFixtures($this->em);
        $docLicencie = $documents->documentLicencie($season);
        $docDirigeant = $documents->documentDirigeant($season, dirigeants: [$dirigeant]);
        $documents->signerParLicencie($docLicencie, $licencie);
        $documents->signerParDirigeant($docDirigeant, $dirigeant);

        $this->em->persist(
            (new CleMouvement())
                ->setDetenteur($detenteur)
                ->setType(CleMouvementType::REMISE)
                ->setQuantite(1)
                ->setDateMouvement(new \DateTimeImmutable('2026-01-10')),
        );

        $this->em->persist(
            (new AttestationCle())
                ->setDetenteur($detenteur)
                ->setSeason($season)
                ->setSignedAt(new \DateTimeImmutable('2026-01-11')),
        );

        $this->em->flush();
        $this->em->clear();

        // Référentiels présents avant purge (conservés ensuite).
        $categoriesAvant = $this->rowCount('category');
        $usersAvant = $this->rowCount('"user"');

        self::assertGreaterThan(0, $this->rowCount('dotation_besoin'), 'Le besoin doit exister avant purge.');
        self::assertGreaterThan(0, $this->rowCount('commande_ligne'), 'La ligne de commande doit exister avant purge.');
        self::assertGreaterThan(0, $this->rowCount('cle_mouvement'), 'Le mouvement de clé doit exister avant purge.');
        self::assertGreaterThan(0, $this->rowCount('attestation_cle'), 'L\'attestation de clés doit exister avant purge.');
        self::assertGreaterThan(0, $this->rowCount('document_signature'), 'Les signatures doivent exister avant purge.');
        self::assertGreaterThan(0, $this->rowCount('document_signable_dirigeant'), 'La désignation nominative doit exister avant purge.');

        // Un PDF resté en local : la purge doit l'emporter, sinon la signature d'un licencié
        // supprimé survit sur le disque sans plus aucune ligne pour la rattacher.
        $pdfStorage = $this->service(PdfStorage::class);
        $pdfLocal = $pdfStorage->ecrire('purge-test_reglement.pdf', '%PDF-1.4 test');
        self::assertFileExists($pdfLocal);

        // — Purge —
        $counts = $this->service(PurgeService::class)->purgeAll();

        // Toutes les tables de données sont vides.
        $videes = [
            'transaction', 'document_signature', 'document_signable_dirigeant', 'document_signable',
            'attestation_cle', 'cle_mouvement', 'commande_ligne', 'dotation_modele_ligne', 'dotation_affectation',
            'dotation_besoin', 'stock_movement', 'commande', 'dotation_modele', 'dossier_club',
            'licencie', 'dirigeant', 'detenteur', 'stock_item', 'fournisseur', 'stock_category', 'team', 'season',
        ];
        foreach ($videes as $table) {
            self::assertSame(0, $this->rowCount($table), sprintf('La table "%s" doit être vide après purge.', $table));
        }

        // Référentiels et comptes conservés intacts.
        self::assertSame($categoriesAvant, $this->rowCount('category'), 'Les catégories FFF sont conservées.');
        self::assertSame($usersAvant, $this->rowCount('"user"'), 'Les comptes admin sont conservés.');

        // Les PDF en attente d'archivage sont emportés avec les lignes qui les décrivaient.
        self::assertFileDoesNotExist($pdfLocal, 'Le PDF local doit être supprimé par la purge.');
        self::assertGreaterThan(0, $counts[PurgeService::CLE_FICHIERS_PDF], 'Le rapport doit compter les fichiers supprimés.');

        // Les séquences repartent de 1 : une nouvelle campagne de test recommence à l'id 1.
        // (licencie et dirigeant ont un uuid pour clé primaire, ils n'ont pas de séquence.)
        self::assertSame(
            1,
            (int) $this->em->getConnection()->fetchOne("SELECT nextval(pg_get_serial_sequence('season', 'id'))"),
            'La séquence de season doit repartir de 1.',
        );
    }
}
