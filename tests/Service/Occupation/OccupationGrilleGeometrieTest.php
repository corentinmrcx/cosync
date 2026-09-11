<?php declare(strict_types=1);

namespace App\Tests\Service\Occupation;

use App\Entity\CreneauOccupation;
use App\Enum\JourSemaine;
use App\Service\Occupation\OccupationGrilleGeometrie;
use PHPUnit\Framework\TestCase;

/**
 * Le placement des blocs de la semaine type.
 *
 * Ce calcul est le seul du module à valoir aussi bien pour l'écran que pour le PDF remis à
 * la mairie : une erreur ici sort sur du papier, où elle ne se corrige plus.
 */
final class OccupationGrilleGeometrieTest extends TestCase
{
    private OccupationGrilleGeometrie $geometrie;

    protected function setUp(): void
    {
        $this->geometrie = new OccupationGrilleGeometrie();
    }

    public function testUneGrilleVideSOuvreSurUneJourneeDeClub(): void
    {
        $plage = $this->geometrie->plage([]);

        self::assertSame(8 * 60, $plage->debut);
        self::assertSame(22 * 60, $plage->fin);
    }

    /**
     * Une semaine qui commence à 17h30 imprimée sur une échelle 8h–22h laisserait les deux
     * tiers de la feuille en blanc : l'amplitude suit les créneaux.
     */
    public function testLAmplitudeSuitLesCreneauxEtSArrondADesHeuresPleines(): void
    {
        $plage = $this->geometrie->plage([
            $this->creneau(JourSemaine::MARDI, '17:30', '19:00'),
            $this->creneau(JourSemaine::VENDREDI, '19:00', '20:45'),
        ]);

        self::assertSame(17 * 60, $plage->debut, 'On descend à l\'heure pleine inférieure.');
        self::assertSame(21 * 60, $plage->fin, 'On monte à l\'heure pleine supérieure.');
    }

    public function testUneGrilleTresResserreeGardeUneAmplitudeLisible(): void
    {
        $plage = $this->geometrie->plage([$this->creneau(JourSemaine::LUNDI, '18:00', '19:00')]);

        self::assertSame(18 * 60, $plage->debut);
        self::assertSame(21 * 60, $plage->fin, 'Sous trois heures, les graduations se toucheraient.');
    }

    /**
     * L'écran ne se resserre jamais : on ne peut pas glisser sur une heure qui n'est pas
     * dessinée, et sur une grille vide il n'y aurait nulle part où poser le premier créneau.
     */
    public function testLEcranMontreLaJourneeEntiereQuoiQuIlArrive(): void
    {
        $plage = $this->geometrie->plageEcran([$this->creneau(JourSemaine::LUNDI, '18:00', '19:30')]);

        self::assertSame(8 * 60, $plage->debut);
        self::assertSame(22 * 60, $plage->fin);
    }

    /** Elle ne s'étend que dans un sens : rogner un créneau reviendrait à cacher une réservation. */
    public function testLEcranSElargitPourUnCreneauHorsDeLaJournee(): void
    {
        $plage = $this->geometrie->plageEcran([
            $this->creneau(JourSemaine::SAMEDI, '07:30', '09:00'),
            $this->creneau(JourSemaine::SAMEDI, '21:30', '23:00'),
        ]);

        self::assertSame(7 * 60, $plage->debut);
        self::assertSame(23 * 60, $plage->fin);
    }

    /** L'amplitude imposée l'emporte sur celle qu'on déduirait des créneaux. */
    public function testUneAmplitudeImposeeSertDEchelleAuPlacement(): void
    {
        $creneaux = [$this->creneau(JourSemaine::LUNDI, '14:00', '15:00')];
        $grille = $this->geometrie->grille($creneaux, $this->geometrie->plageEcran($creneaux));

        self::assertSame(8 * 60, $grille->plage->debut);
        // 14h sur une échelle 8h–22h : six heures sur quatorze.
        self::assertSame(6 / 14, $grille->colonnes[0]->blocs[0]->haut);
        self::assertSame(1 / 14, $grille->colonnes[0]->blocs[0]->hauteur);
    }

    public function testLaSemaineGardeSesSeptColonnesMemeVides(): void
    {
        $grille = $this->geometrie->grille([$this->creneau(JourSemaine::MERCREDI, '14:00', '15:30')]);

        self::assertCount(7, $grille->colonnes);
        self::assertSame(JourSemaine::LUNDI, $grille->colonnes[0]->jour);
        self::assertSame(JourSemaine::DIMANCHE, $grille->colonnes[6]->jour);
        self::assertTrue($grille->colonnes[0]->estVide());
        self::assertCount(1, $grille->colonnes[2]->blocs);
    }

    public function testUnCreneauSeulPrendTouteLaLargeurDeSaColonne(): void
    {
        $grille = $this->geometrie->grille([$this->creneau(JourSemaine::LUNDI, '18:00', '19:30')]);
        $bloc = $grille->colonnes[0]->blocs[0];

        self::assertSame(0.0, $bloc->gauche);
        self::assertSame(1.0, $bloc->largeur);
        // Amplitude 18h–21h30 ramenée à 18h–21h30 → le bloc occupe le premier tiers.
        self::assertSame(0.0, $bloc->haut);
    }

    /**
     * Le cas qui motive tout ce calcul : deux catégories sur les deux moitiés du terrain
     * d'honneur, au même horaire. Elles se partagent la colonne — les superposer en
     * cacherait une, et les refuser bloquerait un usage réel du complexe.
     */
    public function testDeuxCreneauxSimultanesSePartagentLaColonne(): void
    {
        $grille = $this->geometrie->grille([
            $this->creneau(JourSemaine::MERCREDI, '14:00', '15:30'),
            $this->creneau(JourSemaine::MERCREDI, '14:00', '15:30'),
        ]);

        [$premier, $second] = $grille->colonnes[2]->blocs;

        self::assertSame(0.5, $premier->largeur);
        self::assertSame(0.5, $second->largeur);
        self::assertSame(0.0, $premier->gauche);
        self::assertSame(0.5, $second->gauche);
    }

    /** Un chevauchement de 18h ne doit pas rétrécir le créneau isolé de 10h. */
    public function testUnCreneauIsoleNEstPasRetreciParUnChevauchementAilleursDansLaJournee(): void
    {
        $grille = $this->geometrie->grille([
            $this->creneau(JourSemaine::SAMEDI, '10:00', '11:30'),
            $this->creneau(JourSemaine::SAMEDI, '14:00', '16:00'),
            $this->creneau(JourSemaine::SAMEDI, '15:00', '17:00'),
        ]);

        [$matin, $premierApresMidi, $secondApresMidi] = $grille->colonnes[5]->blocs;

        self::assertSame(1.0, $matin->largeur, 'Le matin ne recouvre rien.');
        self::assertSame(0.5, $premierApresMidi->largeur);
        self::assertSame(0.5, $secondApresMidi->largeur);
    }

    /**
     * Trois créneaux qui se suivent en se recouvrant deux à deux forment une seule grappe :
     * sans ce chaînage, le troisième reprendrait la moitié gauche déjà occupée par le
     * deuxième et les deux se superposeraient.
     */
    public function testUnChevauchementEnChaineNeReutilisePasUneVoieOccupee(): void
    {
        $grille = $this->geometrie->grille([
            $this->creneau(JourSemaine::JEUDI, '18:00', '19:30'),
            $this->creneau(JourSemaine::JEUDI, '18:30', '20:00'),
            $this->creneau(JourSemaine::JEUDI, '19:00', '20:30'),
        ]);

        $largeurs = array_map(static fn ($bloc): float => $bloc->largeur, $grille->colonnes[3]->blocs);
        $gauches = array_map(static fn ($bloc): float => $bloc->gauche, $grille->colonnes[3]->blocs);

        self::assertSame([1 / 3, 1 / 3, 1 / 3], $largeurs);
        self::assertSame([0.0, 1 / 3, 2 / 3], $gauches);
    }

    /** Deux créneaux bout à bout ne se recouvrent pas : 19h30 finit là où 19h30 commence. */
    public function testDeuxCreneauxBoutABoutGardentTouteLaLargeur(): void
    {
        $grille = $this->geometrie->grille([
            $this->creneau(JourSemaine::LUNDI, '18:00', '19:30'),
            $this->creneau(JourSemaine::LUNDI, '19:30', '21:00'),
        ]);

        foreach ($grille->colonnes[0]->blocs as $bloc) {
            self::assertSame(1.0, $bloc->largeur);
        }
    }

    public function testLaHauteurDUnBlocEstSaPartDeLAmplitude(): void
    {
        // Amplitude 8h–20h, soit 12 heures : un créneau de 1h30 en occupe le huitième.
        $grille = $this->geometrie->grille([
            $this->creneau(JourSemaine::LUNDI, '08:00', '09:00'),
            $this->creneau(JourSemaine::MARDI, '18:30', '20:00'),
        ]);

        $bloc = $grille->colonnes[1]->blocs[0];

        self::assertSame(0.125, $bloc->hauteur);
        self::assertSame(10.5 / 12, $bloc->haut);
    }

    private function creneau(JourSemaine $jour, string $debut, string $fin): CreneauOccupation
    {
        return (new CreneauOccupation())
            ->setJour($jour)
            ->setHeureDebut($debut)
            ->setHeureFin($fin);
    }
}
