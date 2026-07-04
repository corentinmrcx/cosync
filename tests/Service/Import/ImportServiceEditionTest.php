<?php declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Repository\DirigeantRepository;
use App\Repository\LicencieRepository;
use App\Service\Import\ImportService;

/**
 * Non-régression de l'ancien format « Éditions et extractions » : split « Nom, prénom »,
 * routage libre/dirigeant, ignore les autres types, envoi automatique activé.
 */
final class ImportServiceEditionTest extends ImportIntegrationTestCase
{
    private const HEADERS = [
        'Type licence', 'Nom, prénom', 'Né(e) le', 'Sous catégorie', 'Numéro personne',
        'Email principal', 'Mobile personnel', 'Voie-rue', 'Code postal', 'Bureau distributeur',
    ];

    public function testAncienFormatRouteLibreEtDirigeantEtIgnoreLeReste(): void
    {
        $season = $this->makeSeason();
        $this->makeCategory('SENIOR');
        $this->em->flush();

        $file = $this->makeXlsx(self::HEADERS, [
            ['libre', 'DUPONT Thomas', '12/04/2003', 'Senior', '111111', 'dupont@example.fr', '0670000000', '1 rue A', '51000', 'CHALONS'],
            ['dirigeant', 'MARTIN Kevin', '01/01/1980', 'Président', '222222', 'martin@example.fr', '0680000000', '', '', ''],
            ['educateur', 'IGNORE Moi', '01/01/1990', 'Educateur', '333333', 'ignore@example.fr', '', '', '', ''],
        ]);

        $result = $this->service(ImportService::class)->importFromXlsx($file, $season);

        /** @var LicencieRepository $licencies */
        $licencies = $this->service(LicencieRepository::class);
        /** @var DirigeantRepository $dirigeants */
        $dirigeants = $this->service(DirigeantRepository::class);

        self::assertSame('Éditions et extractions', $result->layoutLabel);
        self::assertTrue($result->emailAutoSend, 'L\'ancien format envoie les liens automatiquement');
        self::assertEmpty($result->errors, implode(' | ', $result->errors));

        $dupont = $licencies->findByNumLicence('111111', $season);
        self::assertNotNull($dupont);
        self::assertSame('DUPONT', $dupont->getNom());
        self::assertSame('Thomas', $dupont->getPrenom());
        self::assertSame('SENIOR', $dupont->getCategory()->getCode());

        self::assertNotNull($dirigeants->findByNumLicence('222222', $season), 'MARTIN est un dirigeant');
        self::assertNull($licencies->findByNumLicence('333333', $season), 'L\'éducateur est ignoré');
        self::assertSame(1, $dirigeants->countBySeason($season), 'Un seul dirigeant (MARTIN)');
    }
}
