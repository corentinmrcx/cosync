<?php declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Season;
use App\Enum\NatureLicence;
use App\Repository\LicencieRepository;
use App\Service\Import\ImportService;

/**
 * Détection « nouveau licencié / renouvellement » à l'import.
 *
 * Source principale : la colonne « Nature » de l'export FootClubs, qui doit fonctionner seule
 * dès la première saison d'utilisation. L'historique des saisons ne sert qu'en renfort et ne
 * doit jamais faire conclure « nouveau » par simple absence de trace.
 */
final class ImportNatureLicenceTest extends ImportIntegrationTestCase
{
    private const HEADERS = [
        'Nom', 'Prénom', 'Numéro personne', 'Sous-catégorie', 'Type',
        'Date de naissance', 'Email', 'Téléphone mobile', 'Adresse 1', 'Code postal', 'Ville', 'Nature',
    ];

    /** Mêmes colonnes, sans « Nature » — cas d'un export plus ancien. */
    private const HEADERS_SANS_NATURE = [
        'Nom', 'Prénom', 'Numéro personne', 'Sous-catégorie', 'Type',
        'Date de naissance', 'Email', 'Téléphone mobile', 'Adresse 1', 'Code postal', 'Ville',
    ];

    private function seed(string $label = '2025-2026'): Season
    {
        $season = $this->makeSeason($label);
        $this->makeCategory('SENIOR');
        $this->em->flush();

        return $season;
    }

    private function ligne(string $numero, ?string $nature): array
    {
        $base = ['MARTIN', 'Kevin', $numero, 'Libre / Senior', 'Joueur', '12/04/2003', 'kevin@example.fr', '0670290965', '', '', ''];

        return $nature === null ? $base : [...$base, $nature];
    }

    private function importer(Season $season, array $headers, array $rows): void
    {
        $this->service(ImportService::class)->importFromXlsx($this->makeXlsx($headers, $rows), $season);
    }

    private function nature(Season $season, string $numero): ?NatureLicence
    {
        /** @var LicencieRepository $repo */
        $repo = $this->service(LicencieRepository::class);

        return $repo->findByNumLicence($numero, $season)?->getNatureLicence();
    }

    public function testColonneNatureRenseigneLesTroisValeurs(): void
    {
        $season = $this->seed();

        $this->importer($season, self::HEADERS, [
            $this->ligne('111', 'Nouvelle demande'),
            [...['DUPONT', 'Thomas', '222', 'Libre / Senior', 'Joueur', '01/01/2000', 't@example.fr', '', '', '', ''], 'Changement de club'],
            [...['DURAND', 'Paul', '333', 'Libre / Senior', 'Joueur', '01/01/2000', 'p@example.fr', '', '', '', ''], 'Renouvellement'],
        ]);

        self::assertSame(NatureLicence::NOUVELLE_DEMANDE, $this->nature($season, '111'));
        self::assertSame(NatureLicence::CHANGEMENT_CLUB, $this->nature($season, '222'));
        self::assertSame(NatureLicence::RENOUVELLEMENT, $this->nature($season, '333'));
    }

    public function testMuteEstConsidereCommeNouveauAuClub(): void
    {
        $season = $this->seed();
        $this->importer($season, self::HEADERS, [$this->ligne('111', 'Changement de club')]);

        /** @var LicencieRepository $repo */
        $repo = $this->service(LicencieRepository::class);
        self::assertTrue($repo->findByNumLicence('111', $season)?->estNouveau());
    }

    public function testCasseEtAccentsTolerees(): void
    {
        $season = $this->seed();
        $this->importer($season, self::HEADERS, [$this->ligne('111', '  RENOUVELLEMENT  ')]);

        self::assertSame(NatureLicence::RENOUVELLEMENT, $this->nature($season, '111'));
    }

    public function testValeurInconnueLaisseLaNatureVide(): void
    {
        $season = $this->seed();
        $this->importer($season, self::HEADERS, [$this->ligne('111', 'Blabla')]);

        self::assertNull($this->nature($season, '111'));
    }

    public function testColonneAbsenteNEmpechePasLImport(): void
    {
        $season = $this->seed();

        $result = $this->service(ImportService::class)->importFromXlsx(
            $this->makeXlsx(self::HEADERS_SANS_NATURE, [$this->ligne('111', null)]),
            $season,
        );

        self::assertSame([], $result->errors);
        self::assertSame(1, $result->created);
        self::assertNull($this->nature($season, '111'));
        self::assertSame(1, $result->natureInconnue);
    }

    public function testHistoriqueMarqueLeRenouvellementQuandLaColonneManque(): void
    {
        $ancienne = $this->seed('2025-2026');
        $this->importer($ancienne, self::HEADERS_SANS_NATURE, [$this->ligne('111', null)]);

        $courante = $this->makeSeason('2026-2027');
        $this->em->flush();
        $this->importer($courante, self::HEADERS_SANS_NATURE, [$this->ligne('111', null)]);

        self::assertSame(NatureLicence::RENOUVELLEMENT, $this->nature($courante, '111'));
    }

    public function testAbsenceDHistoriqueNeConclutJamaisNouveau(): void
    {
        // Le licencié n'existait pas la saison passée, mais rien ne prouve qu'il est nouveau :
        // la saison précédente peut n'avoir jamais été importée dans CoSync.
        $this->seed('2025-2026');
        $courante = $this->makeSeason('2026-2027');
        $this->em->flush();

        $this->importer($courante, self::HEADERS_SANS_NATURE, [$this->ligne('999', null)]);

        self::assertNull($this->nature($courante, '999'));
    }

    public function testColonneNaturePrimeSurLHistorique(): void
    {
        $ancienne = $this->seed('2025-2026');
        $this->importer($ancienne, self::HEADERS_SANS_NATURE, [$this->ligne('111', null)]);

        $courante = $this->makeSeason('2026-2027');
        $this->em->flush();
        $this->importer($courante, self::HEADERS, [$this->ligne('111', 'Nouvelle demande')]);

        self::assertSame(NatureLicence::NOUVELLE_DEMANDE, $this->nature($courante, '111'));
    }

    public function testContradictionEntreExportEtHistoriqueEstSignalee(): void
    {
        $ancienne = $this->seed('2025-2026');
        $this->importer($ancienne, self::HEADERS_SANS_NATURE, [$this->ligne('111', null)]);

        $courante = $this->makeSeason('2026-2027');
        $this->em->flush();

        $result = $this->service(ImportService::class)->importFromXlsx(
            $this->makeXlsx(self::HEADERS, [$this->ligne('111', 'Nouvelle demande')]),
            $courante,
        );

        self::assertNotSame([], $result->notices);
        self::assertStringContainsString('historique', $result->notices[0]);
    }

    public function testReimportNEcrasePasUneCorrectionAdmin(): void
    {
        $season = $this->seed();
        $this->importer($season, self::HEADERS, [$this->ligne('111', 'Renouvellement')]);

        /** @var LicencieRepository $repo */
        $repo     = $this->service(LicencieRepository::class);
        $licencie = $repo->findByNumLicence('111', $season);
        $licencie->setNatureLicence(NatureLicence::NOUVELLE_DEMANDE)->setNatureManuelle(true);
        $this->em->flush();

        $this->importer($season, self::HEADERS, [$this->ligne('111', 'Renouvellement')]);

        self::assertSame(NatureLicence::NOUVELLE_DEMANDE, $this->nature($season, '111'));
    }

    public function testCelluleVideNEcrasePasUneNatureDejaConnue(): void
    {
        $season = $this->seed();
        $this->importer($season, self::HEADERS, [$this->ligne('111', 'Renouvellement')]);
        $this->importer($season, self::HEADERS, [$this->ligne('111', '')]);

        self::assertSame(NatureLicence::RENOUVELLEMENT, $this->nature($season, '111'));
    }

    public function testCompteursDuRapportDImport(): void
    {
        $season = $this->seed();

        $result = $this->service(ImportService::class)->importFromXlsx(
            $this->makeXlsx(self::HEADERS, [
                $this->ligne('111', 'Nouvelle demande'),
                [...['DUPONT', 'Thomas', '222', 'Libre / Senior', 'Joueur', '01/01/2000', 't@example.fr', '', '', '', ''], 'Renouvellement'],
                [...['DURAND', 'Paul', '333', 'Libre / Senior', 'Joueur', '01/01/2000', 'p@example.fr', '', '', '', ''], ''],
            ]),
            $season,
        );

        self::assertSame(1, $result->nouveaux);
        self::assertSame(1, $result->renouvellements);
        self::assertSame(1, $result->natureInconnue);
    }
}
