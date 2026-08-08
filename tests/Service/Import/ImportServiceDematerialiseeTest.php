<?php declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Season;
use App\Enum\DirigeantRole;
use App\Enum\LicenceStatus;
use App\Repository\DirigeantRepository;
use App\Repository\LicencieRepository;
use App\Service\Import\ImportService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Import du nouvel export FFF « Licences dématérialisées » : Joueur → licencié, tout le reste →
 * dirigeant, aucun mail automatique, déduplication par cible.
 */
final class ImportServiceDematerialiseeTest extends ImportIntegrationTestCase
{
    private const HEADERS = [
        'Nom', 'Prénom', 'Numéro personne', 'Sous-catégorie', 'Type',
        'Date de naissance', 'Email', 'Téléphone mobile', 'Adresse 1', 'Code postal', 'Ville',
    ];

    private function seedSeasonAndCategories(): Season
    {
        $season = $this->makeSeason();
        $this->makeCategory('SENIOR');
        $this->makeCategory('U15', true);
        $this->makeCategory('VETERAN');
        $this->em->flush();

        return $season;
    }

    private function fichierComplet(): UploadedFile
    {
        return $this->makeXlsx(self::HEADERS, [
            ['HUVELLE', 'Theo', '9603089611', 'Libre / Senior', 'Joueur', '12/04/2003', 'theo@example.fr', '0670290965', '5 rue des Sports', '51000', 'CHALONS'],
            ['VAUQUELIN', 'Arthur', '9604605202', 'Libre / U15 (- 15 ans)', 'Joueur', '26/10/2012', 'earl@example.fr', '0681935126', '3 rue du Stade', '51520', 'SOUDRON'],
            ['FLEURIET', 'Alexandre', '2020670911', 'Dirigeant / Dirigeant', 'Dirigeant', '17/02/1984', 'fleuriet@example.fr', '0648195683', '', '', ''],
            ['WILK', 'Lukas', '9603273997', 'Arbitre / Jeune arbitre', 'Arbitre', '07/07/2011', 'wilk@example.fr', '0624452703', '', '', ''],
            // Multi-rôles : même numéro en Joueur ET en Dirigeant
            ['CUSSANT', 'Julien', '2543060379', 'Libre / Vétéran', 'Joueur', '08/04/1987', 'cussant@example.fr', '0682919552', '', '', ''],
            ['CUSSANT', 'Julien', '2543060379', 'Dirigeant / Dirigeant', 'Dirigeant', '08/04/1987', 'cussant@example.fr', '0682919552', '', '', ''],
        ]);
    }

    public function testJoueursDeviennentLicenciesEtLeResteDirigeants(): void
    {
        $season = $this->seedSeasonAndCategories();

        $result = $this->service(ImportService::class)->importFromXlsx($this->fichierComplet(), $season);

        /** @var LicencieRepository $licencies */
        $licencies = $this->service(LicencieRepository::class);
        /** @var DirigeantRepository $dirigeants */
        $dirigeants = $this->service(DirigeantRepository::class);

        self::assertSame('Licences dématérialisées', $result->layoutLabel);
        self::assertCount(3, $licencies->findBySeason($season), 'HUVELLE, VAUQUELIN, CUSSANT');
        self::assertSame(3, $dirigeants->countBySeason($season), 'FLEURIET, WILK, CUSSANT');
        self::assertEmpty($result->errors, implode(' | ', $result->errors));
    }

    public function testAucunMailEnvoyeSurCeFormat(): void
    {
        $season = $this->seedSeasonAndCategories();

        $result = $this->service(ImportService::class)->importFromXlsx($this->fichierComplet(), $season);

        self::assertFalse($result->emailAutoSend);
        self::assertSame(0, $result->emailsSent);
        self::assertSame(0, $result->emailsFailed);

        // Aucun mail parti → aucune date d'envoi ni statut « Lien envoyé » : le dossier reste « Importé ».
        /** @var LicencieRepository $licencies */
        $licencies = $this->service(LicencieRepository::class);
        $huvelle   = $licencies->findByNumLicence('9603089611', $season);
        self::assertNull($huvelle?->getLinkSentAt());
        self::assertSame(LicenceStatus::IMPORTED, $huvelle?->getDossierClub()?->getStatus());
    }

    public function testCategorieEtAdresseMappees(): void
    {
        $season = $this->seedSeasonAndCategories();

        $this->service(ImportService::class)->importFromXlsx($this->fichierComplet(), $season);

        /** @var LicencieRepository $licencies */
        $licencies = $this->service(LicencieRepository::class);

        $huvelle = $licencies->findByNumLicence('9603089611', $season);
        self::assertNotNull($huvelle);
        self::assertSame('SENIOR', $huvelle->getCategory()->getCode());
        self::assertSame('HUVELLE', $huvelle->getNom());
        self::assertSame('Theo', $huvelle->getPrenom());

        $vauquelin = $licencies->findByNumLicence('9604605202', $season);
        self::assertNotNull($vauquelin);
        self::assertSame('U15', $vauquelin->getCategory()->getCode());
        self::assertSame('3 rue du Stade', $vauquelin->getVoieRue());
        self::assertSame('51520', $vauquelin->getCodePostal());
        self::assertSame('SOUDRON', $vauquelin->getVille());
    }

    public function testPersonneMultiRoleCreeLicencieEtDirigeant(): void
    {
        $season = $this->seedSeasonAndCategories();

        $this->service(ImportService::class)->importFromXlsx($this->fichierComplet(), $season);

        /** @var LicencieRepository $licencies */
        $licencies = $this->service(LicencieRepository::class);
        /** @var DirigeantRepository $dirigeants */
        $dirigeants = $this->service(DirigeantRepository::class);

        $licencie  = $licencies->findByNumLicence('2543060379', $season);
        $dirigeant = $dirigeants->findByNumLicence('2543060379', $season);

        self::assertNotNull($licencie, 'CUSSANT doit exister comme licencié (ligne Joueur)');
        self::assertNotNull($dirigeant, 'CUSSANT doit aussi exister comme dirigeant (ligne Dirigeant)');
        self::assertSame('VETERAN', $licencie->getCategory()->getCode());
    }

    /**
     * La sous-catégorie FFF (« Jeune Arbitre », « Educateur Fédéral »…) ne dit rien du rôle interne
     * au club : tout dirigeant importé arrive en « Dirigeant », charge à l'admin de le promouvoir.
     */
    public function testUnDirigeantImporteArriveAvecLeRoleParDefaut(): void
    {
        $season = $this->seedSeasonAndCategories();

        $this->service(ImportService::class)->importFromXlsx($this->fichierComplet(), $season);

        /** @var DirigeantRepository $dirigeants */
        $dirigeants = $this->service(DirigeantRepository::class);

        $arbitre = $dirigeants->findByNumLicence('9603273997', $season);
        self::assertNotNull($arbitre);
        self::assertSame(DirigeantRole::DIRIGEANT, $arbitre->getRole());
    }

    /** Un rôle attribué à la main par l'admin survit à un ré-import du même fichier. */
    public function testUnReimportNEcrasePasUnRoleAttribueALaMain(): void
    {
        $season = $this->seedSeasonAndCategories();

        /** @var DirigeantRepository $dirigeants */
        $dirigeants = $this->service(DirigeantRepository::class);

        $this->service(ImportService::class)->importFromXlsx($this->fichierComplet(), $season);

        $arbitre = $dirigeants->findByNumLicence('9603273997', $season);
        self::assertNotNull($arbitre);
        $arbitre->setRole(DirigeantRole::RESPONSABLE_FOOT);
        $this->em->flush();
        $this->em->clear();

        $this->service(ImportService::class)->importFromXlsx($this->fichierComplet(), $season);

        $arbitre = $dirigeants->findByNumLicence('9603273997', $season);
        self::assertNotNull($arbitre);
        self::assertSame(DirigeantRole::RESPONSABLE_FOOT, $arbitre->getRole());
    }

    public function testSeniorU20DevientSeniorEtFootLoisirEstReconnu(): void
    {
        $season = $this->makeSeason();
        $this->makeCategory('SENIOR');
        $this->makeCategory('FOOTLOISIR');
        $this->em->flush();

        $file = $this->makeXlsx(self::HEADERS, [
            ['PRUVOST', 'Steven', '2547954749', 'Libre / Senior U20 (- 20 ans)', 'Joueur', '03/07/2007', 'p@example.fr', '0767994810', '', '', ''],
            ['WILK', 'Anthony', '9603532680', 'Foot Loisir / Foot Loisir', 'Joueur', '09/12/1983', 'w@example.fr', '0624452703', '', '', ''],
        ]);

        $result = $this->service(ImportService::class)->importFromXlsx($file, $season);

        /** @var LicencieRepository $licencies */
        $licencies = $this->service(LicencieRepository::class);

        self::assertEmpty($result->errors, implode(' | ', $result->errors));
        self::assertSame('SENIOR', $licencies->findByNumLicence('2547954749', $season)?->getCategory()->getCode(), 'Senior U20 est un sénior');
        self::assertSame('FOOTLOISIR', $licencies->findByNumLicence('9603532680', $season)?->getCategory()->getCode());
    }

    public function testReimportEstIdempotent(): void
    {
        $season = $this->seedSeasonAndCategories();
        $service = $this->service(ImportService::class);

        $service->importFromXlsx($this->fichierComplet(), $season);
        $second = $service->importFromXlsx($this->fichierComplet(), $season);

        /** @var LicencieRepository $licencies */
        $licencies = $this->service(LicencieRepository::class);

        self::assertSame(0, $second->created, 'Aucune création au second import');
        self::assertGreaterThan(0, $second->updated);
        self::assertCount(3, $licencies->findBySeason($season), 'Toujours 3 licenciés, pas de doublon');
    }
}
