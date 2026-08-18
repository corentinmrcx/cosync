<?php declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Season;
use App\Repository\DirigeantRepository;
use App\Repository\LicencieRepository;
use App\Service\Import\ImportService;

/**
 * Garde-fou de l'import dématérialisé : seuls les dossiers que le licencié a remplis côté FFF
 * entrent dans CoSync. Un export non filtré contient tout le fichier des licences du club — c'est
 * exactement ce qui a peuplé la prod de fiches fantômes.
 */
final class ImportStatutDossierTest extends ImportIntegrationTestCase
{
    private const HEADERS = [
        'Nom', 'Prénom', 'Numéro personne', 'Sous-catégorie', 'Type', 'Statut',
        'Date de naissance', 'Email', 'Téléphone mobile',
    ];

    private function seedSeason(): Season
    {
        $season = $this->makeSeason();
        $this->makeCategory('SENIOR');
        $this->em->flush();

        return $season;
    }

    /**
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $lignes [nom, num, type, statut]
     */
    private function importer(Season $season, array $lignes): \App\DTO\ImportResultData
    {
        $rows = array_map(
            static fn (array $l): array => [
                $l[0], 'Test', $l[1], 'Libre / Senior', $l[2], $l[3],
                '12/04/2003', strtolower($l[0]) . '@example.fr', '0670290965',
            ],
            $lignes,
        );

        return $this->service(ImportService::class)->importFromXlsx($this->makeXlsx(self::HEADERS, $rows), $season);
    }

    public function testSeulsLesDossiersRemplisCoteFffSontImportes(): void
    {
        $season = $this->seedSeason();

        $result = $this->importer($season, [
            ['SIGNATURE', '1000000001', 'Joueur', 'En attente signature club'],
            ['LIGUE', '1000000002', 'Joueur', 'En attente validation ligue'],
            ['ATTESTEE', '1000000003', 'Joueur', 'Attestation licence créée'],
            ['CONTACT', '1000000004', 'Joueur', 'Prise de contact'],
            ['REJETE', '1000000005', 'Joueur', 'Rejeté par club'],
        ]);

        /** @var LicencieRepository $licencies */
        $licencies = $this->service(LicencieRepository::class);

        self::assertSame(3, $result->created);
        self::assertSame(2, $result->ignores(), 'la prise de contact et le rejet sont écartés');
        self::assertCount(3, $licencies->findBySeason($season));
        self::assertNull($licencies->findByNumLicence('1000000004', $season), 'la prise de contact n\'entre pas dans l\'effectif');
        self::assertNull($licencies->findByNumLicence('1000000005', $season), 'un dossier rejeté n\'entre pas dans l\'effectif');
        self::assertSame(
            ['Prise de contact' => 1, 'Rejeté par club' => 1],
            $result->ignoresParStatut,
            'le rapport regroupe les lignes écartées par libellé de statut',
        );
        self::assertSame([], $result->statutsInconnus);
    }

    /** Le filtre vaut pour les dirigeants aussi : le même fichier les crée par la même passe. */
    public function testLeFiltreSAppliqueAussiAuxDirigeants(): void
    {
        $season = $this->seedSeason();

        $result = $this->importer($season, [
            ['ENCADRANT', '1000000006', 'Dirigeant', 'Attestation licence créée'],
            ['CURIEUX', '1000000007', 'Dirigeant', 'Prise de contact'],
        ]);

        /** @var DirigeantRepository $dirigeants */
        $dirigeants = $this->service(DirigeantRepository::class);

        self::assertSame(1, $result->created);
        self::assertCount(1, $dirigeants->findBySeason($season));
        self::assertNull($dirigeants->findByNumLicence('1000000007', $season));
    }

    /**
     * Un libellé que CoSync ne connaît pas n'est jamais importé « dans le doute » : le rapport
     * l'annonce, quitte à faire relancer l'import. L'inverse remplirait l'effectif en silence
     * le jour où la FFF renomme ses statuts.
     */
    public function testUnStatutInconnuEstEcarteEtSignale(): void
    {
        $season = $this->seedSeason();

        $result = $this->importer($season, [
            ['MYSTERE', '1000000008', 'Joueur', 'En cours de je-ne-sais-quoi'],
        ]);

        self::assertSame(0, $result->created);
        self::assertSame(1, $result->ignores());
        self::assertSame(['En cours de je-ne-sais-quoi'], $result->statutsInconnus);
    }

    /**
     * L'ancien export n'a pas de colonne « Statut » : il ne contient que des licences signées,
     * il n'y a rien à filtrer — et surtout rien à écarter par excès de zèle.
     */
    public function testLAncienFormatSansColonneStatutNEstPasFiltre(): void
    {
        $season = $this->seedSeason();

        $result = $this->service(ImportService::class)->importFromXlsx(
            $this->makeXlsx(
                ['Type licence', 'Nom, prénom', 'Né(e) le', 'Sous catégorie', 'Numéro personne', 'Email principal', 'Mobile personnel'],
                [['Libre', 'ARDINAT Quentin', '27/11/2000', 'Senior', '1000000009', 'q@example.fr', '0650840821']],
            ),
            $season,
        );

        self::assertSame('Éditions et extractions', $result->layoutLabel);
        self::assertSame(1, $result->created);
        self::assertSame(0, $result->ignores());
    }
}
