<?php declare(strict_types=1);

namespace App\Tests\Service\Planning;

use App\Service\Planning\Import\CollagePlanningParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le collage est la voie de secours quand l'API fédérale n'est pas joignable depuis le
 * serveur, et la seule voie pour les plateaux U7/U9 que la FFF ne publie pas.
 *
 * Ce qui est vérifié ici tient à une chose : **rien ne doit être deviné**. Un planning
 * part dans les boîtes aux lettres ; une date inventée à partir d'une ligne mal lue
 * enverrait des habitants au stade un jour sans match.
 */
final class CollagePlanningParserTest extends TestCase
{
    private CollagePlanningParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CollagePlanningParser();
    }

    public function testLitUnTableauCollePuisTrieParDateEtHeure(): void
    {
        $apercu = $this->parser->analyser(
            "15/03/2026\t14:30\tSéniors\tSaint-Memmie FC\n"
            . "14/03/2026\t09:30\tU13\tSermaiziennes US\n"
            . "14/03/2026\t14:30\tPlateau U7",
            2026,
        );

        self::assertCount(3, $apercu->matchs);
        self::assertSame([], $apercu->ignorees);

        self::assertSame('2026-03-14', $apercu->matchs[0]->date->format('Y-m-d'));
        self::assertSame('09:30', $apercu->matchs[0]->heure);
        self::assertSame('2026-03-14', $apercu->matchs[1]->date->format('Y-m-d'));
        self::assertSame('14:30', $apercu->matchs[1]->heure);
        self::assertSame('2026-03-15', $apercu->matchs[2]->date->format('Y-m-d'));
    }

    /** Un plateau n'a pas d'adversaire, et ce n'est pas une ligne incomplète. */
    public function testUnPlateauSansAdversaireResteUnMatchValide(): void
    {
        $apercu = $this->parser->analyser("14/03/2026\t14:30\tPlateau U7", 2026);

        self::assertCount(1, $apercu->matchs);
        self::assertSame('Plateau U7', $apercu->matchs[0]->categorie);
        self::assertNull($apercu->matchs[0]->adversaire);
    }

    /** @return iterable<string, array{string, string, ?string, string, ?string}> */
    public static function formatsReels(): iterable
    {
        yield 'date française et heure en h' => ['09/05/26  9h30  U13  Bignic/Saulx', '2026-05-09', '09:30', 'U13', 'Bignic/Saulx'];
        yield 'date ISO et séparateur point-virgule' => ['2026-03-15;15:00;Séniors;Saint-Memmie FC', '2026-03-15', '15:00', 'Séniors', 'Saint-Memmie FC'];
        yield 'mois en toutes lettres' => ['20 septembre 2026  15h00  Séniors D3  ARGONNE FC', '2026-09-20', '15:00', 'Séniors D3', 'ARGONNE FC'];
        yield 'jour de semaine et mois abrégé' => ['Samedi 3 oct. 2026   15H30   U16   COTE DES BLANCS FC', '2026-10-03', '15:30', 'U16', 'COTE DES BLANCS FC'];
        yield 'préfixe vs devant l\'adversaire' => ["15/03/2026\t10:00\tU15\tvs Couvrot US", '2026-03-15', '10:00', 'U15', 'Couvrot US'];
        yield 'tiret devant l\'adversaire' => ["15/03/2026\t10:00\tU15\t- Couvrot US", '2026-03-15', '10:00', 'U15', 'Couvrot US'];
        yield 'horaire non fixé' => ["15/03/2026\tPlateau U9", '2026-03-15', null, 'Plateau U9', null];
    }

    #[DataProvider('formatsReels')]
    public function testReconnaitLesFormatsQueLeClubColleReellement(
        string $ligne,
        string $dateAttendue,
        ?string $heureAttendue,
        string $categorieAttendue,
        ?string $adversaireAttendu,
    ): void {
        $apercu = $this->parser->analyser($ligne, 2026);

        self::assertCount(1, $apercu->matchs, 'Ligne non reconnue : ' . $ligne);
        self::assertSame($dateAttendue, $apercu->matchs[0]->date->format('Y-m-d'));
        self::assertSame($heureAttendue, $apercu->matchs[0]->heure);
        self::assertSame($categorieAttendue, $apercu->matchs[0]->categorie);
        self::assertSame($adversaireAttendu, $apercu->matchs[0]->adversaire);
    }

    /**
     * Le cœur du contrat : ce qui n'est pas compris est **rendu tel quel**, jamais
     * transformé en match approximatif.
     */
    public function testUneLigneSansDateEstRejeteeEtNonDevinee(): void
    {
        $apercu = $this->parser->analyser(
            "Calendrier des matchs à domicile\n"
            . "14/03/2026\t09:30\tU13\tSermaiziennes US\n"
            . 'Contact : 06 80 67 15 48',
            2026,
        );

        self::assertCount(1, $apercu->matchs);
        self::assertSame(
            ['Calendrier des matchs à domicile', 'Contact : 06 80 67 15 48'],
            $apercu->ignorees,
        );
        self::assertTrue($apercu->aDesRejets());
    }

    /** Une date sans rien pour la qualifier ne fait pas un match imprimable. */
    public function testUneDateSeuleNeFaitPasUnMatch(): void
    {
        $apercu = $this->parser->analyser('14/03/2026', 2026);

        self::assertTrue($apercu->estVide());
        self::assertSame(['14/03/2026'], $apercu->ignorees);
    }

    /** Une date impossible n'est pas repoussée au mois suivant : elle est rejetée. */
    public function testUneDateInvalideEstRejetee(): void
    {
        $apercu = $this->parser->analyser("31/02/2026\t15:00\tSéniors\tCouvrot US", 2026);

        self::assertTrue($apercu->estVide());
    }

    /**
     * Une année absente prend celle de la saison de travail, pas l'année courante : un
     * planning de janvier collé en décembre porterait sinon l'année qui s'achève.
     */
    public function testUneAnneeAbsentePrendCelleDeLaSaison(): void
    {
        $apercu = $this->parser->analyser("10/01\t15:00\tSéniors\tCouvrot US", 2027);

        self::assertCount(1, $apercu->matchs);
        self::assertSame('2027-01-10', $apercu->matchs[0]->date->format('Y-m-d'));
    }

    public function testLesColonnesEnTropDeviennentLaNote(): void
    {
        $apercu = $this->parser->analyser("15/03/2026\t15:00\tSéniors\tSaint-Memmie FC\tterrain annexe", 2026);

        self::assertSame('terrain annexe', $apercu->matchs[0]->note);
    }

    public function testUnTexteVideNeRendRien(): void
    {
        $apercu = $this->parser->analyser("\n   \n\n", 2026);

        self::assertTrue($apercu->estVide());
        self::assertFalse($apercu->aDesRejets());
    }
}
