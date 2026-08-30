<?php declare(strict_types=1);

namespace App\Tests\Service\Planning;

use App\Service\Planning\Fff\FffMatchMapper;
use PHPUnit\Framework\TestCase;

/**
 * Le mapper est testé sur un extrait **réel** de la réponse de l'API DOFA pour le Foyer
 * de Soudron (`tests/Support/fixtures/fff_matchs_soudron.json`).
 *
 * C'est délibéré : les pièges de cette intégration ne sont pas dans le format déclaré mais
 * dans ce que l'API renvoie vraiment — un `away` à null pour une équipe exempte, un
 * terrain pas encore affecté, des heures écrites `15H30`. Une fixture inventée les
 * aurait tous manqués.
 */
final class FffMatchMapperTest extends TestCase
{
    private const CLUB_NO = 194947;

    /** @return list<array<string, mixed>> */
    private function payload(): array
    {
        $json = file_get_contents(__DIR__ . '/../../Support/fixtures/fff_matchs_soudron.json');
        self::assertIsString($json);

        /** @var list<array<string, mixed>> $lignes */
        $lignes = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $lignes;
    }

    public function testNeGardeQueLesMatchsJouesADomicile(): void
    {
        $matchs = (new FffMatchMapper())->domicile($this->payload(), self::CLUB_NO);

        // 7 lignes en entrée : 1 à l'extérieur, 1 exemption et 1 inexploitable sont écartées.
        self::assertCount(4, $matchs);

        foreach ($matchs as $match) {
            self::assertNotSame('COUVROT US', $match->adversaire, 'Un match à l\'extérieur s\'est glissé dans le planning.');
        }
    }

    public function testLHeureFederaleEstTraduiteEnFormatImprimable(): void
    {
        $matchs = (new FffMatchMapper())->domicile($this->payload(), self::CLUB_NO);

        foreach ($matchs as $match) {
            self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', (string) $match->heure);
        }
    }

    /**
     * La FFF rend `away: null` quand l'équipe est **exempte** de la journée : elle publie
     * une ligne avec notre club en `home`, mais aucune rencontre n'a lieu.
     *
     * L'inscrire au planning ferait tondre la mairie pour rien et enverrait des habitants
     * au stade un jour sans match. Un match sans adversaire n'est réel que saisi à la
     * main — c'est alors un plateau.
     */
    public function testUneEquipeExempteNEstPasUnMatchADomicile(): void
    {
        $matchs = (new FffMatchMapper())->domicile($this->payload(), self::CLUB_NO);

        foreach ($matchs as $match) {
            self::assertNotSame(56656241, $match->fffMaNo, 'Une exemption a été prise pour un match à domicile.');
            self::assertNotNull($match->adversaire);
        }
    }

    /**
     * La FFF classe l'équipe en `U17` alors qu'elle joue la compétition `U16 DISTRICT`.
     * C'est « U16 » que le village reconnaît : la compétition prime.
     */
    public function testLaCategorieSuitLaCompetitionPlutotQueLeCodeFederal(): void
    {
        $matchs = (new FffMatchMapper())->domicile($this->payload(), self::CLUB_NO);

        self::assertSame('U16', $this->parMaNo($matchs, 56655217)->categorie);
    }

    /**
     * Le terrain est souvent affecté après la parution du calendrier. Le match a bien
     * lieu : filtrer sur le terrain ferait disparaître du planning la moitié des
     * rencontres à venir. C'est `home.club.cl_no` qui décide du domicile, et lui seul.
     */
    public function testUnTerrainNonAffecteNEmpechePasLeMatch(): void
    {
        $matchs = (new FffMatchMapper())->domicile($this->payload(), self::CLUB_NO);
        $sansTerrain = $this->parMaNo($matchs, 56653790);

        self::assertNull($sansTerrain->fffTerrain, 'Un terrain absent doit rester null, pas devenir une chaîne vide.');
        self::assertSame('BIGNIC/SAULX', $sansTerrain->adversaire);
    }

    public function testUneCompetitionSansClasseDAgeReplieSurLeCodeCategorie(): void
    {
        $matchs = (new FffMatchMapper())->domicile($this->payload(), self::CLUB_NO);
        $seniors = $this->parMaNo($matchs, 56649846);

        self::assertSame('Séniors', $seniors->categorie);
        self::assertSame('ARGONNE FC', $seniors->adversaire);
        self::assertSame('COMPLEXE SPORTIF DE SOUDRON', $seniors->fffTerrain);
        self::assertSame('SENIORS DISTRICT 3', $seniors->fffCompetition);
    }

    /**
     * `date` arrive en ISO à minuit UTC. Une conversion de fuseau ferait reculer d'un
     * jour tous les matchs en heure d'été : le 20 septembre s'imprimerait le 19.
     */
    public function testLaDateNEstJamaisDecaleeParUnFuseauHoraire(): void
    {
        $tzInitial = date_default_timezone_get();
        date_default_timezone_set('Pacific/Kiritimati'); // UTC+14, le pire des cas

        try {
            $matchs = (new FffMatchMapper())->domicile($this->payload(), self::CLUB_NO);

            self::assertSame('2026-09-20', $this->parMaNo($matchs, 56649846)->date->format('Y-m-d'));
        } finally {
            date_default_timezone_set($tzInitial);
        }
    }

    /** Un report est une simple date différente : c'est `date` qui fait foi, pas `initial_date`. */
    public function testUnMatchReporteEstPrisALaNouvelleDate(): void
    {
        $matchs = (new FffMatchMapper())->domicile($this->payload(), self::CLUB_NO);
        $reporte = $this->parMaNo($matchs, 56697445);

        self::assertSame('2026-09-09', $reporte->date->format('Y-m-d'));
        self::assertSame('18:00', $reporte->heure);
    }

    /** Une ligne sans date ni identifiant ne doit ni planter, ni entrer au planning. */
    public function testUneLigneInexploitableEstIgnoreeSansErreur(): void
    {
        $matchs = (new FffMatchMapper())->domicile($this->payload(), self::CLUB_NO);

        foreach ($matchs as $match) {
            self::assertNotNull($match->fffMaNo);
        }
    }

    public function testUnClubInconnuNeRameneRien(): void
    {
        self::assertSame([], (new FffMatchMapper())->domicile($this->payload(), 999999));
    }

    /** @param list<\App\DTO\Planning\MatchImporteData> $matchs */
    private function parMaNo(array $matchs, int $maNo): \App\DTO\Planning\MatchImporteData
    {
        foreach ($matchs as $match) {
            if ($match->fffMaNo === $maNo) {
                return $match;
            }
        }

        self::fail(sprintf('Match %d absent du résultat.', $maNo));
    }
}
