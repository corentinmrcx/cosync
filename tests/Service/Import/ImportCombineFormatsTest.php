<?php declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Season;
use App\Repository\LicencieRepository;
use App\Service\Import\ImportService;

/**
 * On doit pouvoir importer les deux exports (dématérialisé + éditions/extractions) l'un après
 * l'autre : même clé num_licence → pas de doublon, et un export plus pauvre n'efface jamais les
 * données déjà remplies par l'autre.
 */
final class ImportCombineFormatsTest extends ImportIntegrationTestCase
{
    private const HEADERS_DEMAT = [
        'Nom', 'Prénom', 'Numéro personne', 'Sous-catégorie', 'Type',
        'Date de naissance', 'Email', 'Téléphone mobile', 'Adresse 1', 'Code postal', 'Ville',
    ];

    private const HEADERS_EDITION = [
        'Type licence', 'Nom, prénom', 'Né(e) le', 'Sous catégorie', 'Numéro personne',
        'Email principal', 'Mobile personnel', 'Voie-rue', 'Code postal', 'Bureau distributeur',
    ];

    public function testUnSecondImportPlusPauvreNEffacePasLesDonnees(): void
    {
        $season = $this->makeSeason();
        $this->makeCategory('SENIOR');
        $this->em->flush();

        $service = $this->service(ImportService::class);

        // 1) Import dématérialisé riche.
        $demat = $this->makeXlsx(self::HEADERS_DEMAT, [
            ['HUVELLE', 'Theo', '9603089611', 'Libre / Senior', 'Joueur', '12/04/2003', 'theo@example.fr', '0670290965', '5 rue des Sports', '51000', 'SOUDRON'],
        ]);
        $service->importFromXlsx($demat, $season);

        // 2) Import ancien format, SANS email ni adresse pour le même licencié.
        $edition = $this->makeXlsx(self::HEADERS_EDITION, [
            ['libre', 'HUVELLE Theo', '12/04/2003', 'Senior', '9603089611', '', '', '', '', ''],
        ]);
        $result = $service->importFromXlsx($edition, $season);

        /** @var LicencieRepository $licencies */
        $licencies = $this->service(LicencieRepository::class);

        // Pas de doublon, c'est bien une mise à jour.
        self::assertCount(1, $licencies->findBySeason($season));
        self::assertSame(0, $result->created);
        self::assertSame(1, $result->updated);

        // Les données riches du premier import survivent au second (plus pauvre).
        $huvelle = $licencies->findByNumLicence('9603089611', $season);
        self::assertNotNull($huvelle);
        self::assertSame('theo@example.fr', $huvelle->getEmail(), 'email non effacé');
        self::assertSame('SOUDRON', $huvelle->getVille(), 'ville non effacée');
        self::assertSame('5 rue des Sports', $huvelle->getVoieRue(), 'rue non effacée');
    }

    public function testServiceReutilisableEntreDeuxImportsAvecDirigeants(): void
    {
        $season = $this->makeSeason();
        $this->em->flush();
        $seasonId = $season->getId();
        $service = $this->service(ImportService::class);

        $file1 = $this->makeXlsx(self::HEADERS_DEMAT, [
            ['DUPONT', 'Jean', '111111', 'Dirigeant / Président', 'Dirigeant', '01/01/1980', 'a@example.fr', '', '', '', ''],
        ]);
        $service->importFromXlsx($file1, $season);

        // Simule une seconde requête : EM vidé, service (et son cache de rôles) réutilisé.
        $this->em->clear();
        $season = $this->em->getRepository(Season::class)->find($seasonId);

        $file2 = $this->makeXlsx(self::HEADERS_DEMAT, [
            ['MARTIN', 'Paul', '222222', 'Dirigeant / Président', 'Dirigeant', '02/02/1981', 'b@example.fr', '', '', '', ''],
        ]);
        $result = $service->importFromXlsx($file2, $season);

        self::assertEmpty($result->errors, implode(' | ', $result->errors));
        self::assertSame(1, $result->created);
    }

    public function testUnSecondImportAvecValeurMetAJour(): void
    {
        $season = $this->makeSeason();
        $this->makeCategory('SENIOR');
        $this->em->flush();

        $service = $this->service(ImportService::class);

        $demat = $this->makeXlsx(self::HEADERS_DEMAT, [
            ['HUVELLE', 'Theo', '9603089611', 'Libre / Senior', 'Joueur', '12/04/2003', 'ancien@example.fr', '0670290965', '', '', ''],
        ]);
        $service->importFromXlsx($demat, $season);

        // Nouvel email non vide → doit mettre à jour.
        $edition = $this->makeXlsx(self::HEADERS_EDITION, [
            ['libre', 'HUVELLE Theo', '12/04/2003', 'Senior', '9603089611', 'nouveau@example.fr', '', '', '', ''],
        ]);
        $service->importFromXlsx($edition, $season);

        /** @var LicencieRepository $licencies */
        $licencies = $this->service(LicencieRepository::class);

        self::assertSame('nouveau@example.fr', $licencies->findByNumLicence('9603089611', $season)?->getEmail());
    }
}
