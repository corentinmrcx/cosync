<?php declare(strict_types=1);

namespace App\Service\Occupation;

use App\DTO\Occupation\BlocPlace;
use App\DTO\Occupation\ColonneJour;
use App\DTO\Occupation\GrilleOccupation;
use App\DTO\Occupation\PlageHoraire;
use App\Entity\CreneauOccupation;
use App\Enum\JourSemaine;

/**
 * Où tombe chaque bloc dans la semaine. Calcul pur : ni base, ni template, ni unité.
 *
 * Cette classe existe parce que **l'écran et le PDF doivent montrer la même grille**. DomPDF
 * ne connaît ni flexbox ni `calc()` : la feuille de la mairie se compose en positionnement
 * absolu, donc le placement doit de toute façon être calculé en PHP. Le faire une seconde
 * fois en CSS ou dans une librairie de calendrier JavaScript donnerait deux mises en page
 * qui divergeraient au premier ajustement — et la mairie recevrait un document qui ne
 * ressemble plus à ce que l'admin a composé.
 *
 * C'est le même parti que {@see \App\Service\Pdf\FlyerGeometrie}, à ceci près qu'ici les
 * résultats sont sans dimension : l'écran les lit en pourcentages, le PDF en millimètres.
 */
final class OccupationGrilleGeometrie
{
    /**
     * L'amplitude de référence : la journée d'un complexe sportif.
     *
     * C'est celle de l'écran, **toujours**, même sur une grille vide ou resserrée. Une
     * échelle qui se rétracte sur les créneaux existants n'offre plus de place où en poser
     * un nouveau : on ne peut pas glisser sur une heure qui n'est pas dessinée, et le
     * premier créneau d'une saison n'aurait aucun endroit où naître.
     */
    private const DEBUT_JOURNEE = 8 * 60;

    private const FIN_JOURNEE = 22 * 60;

    /** Sous cette amplitude, les graduations se touchent et la grille devient illisible. */
    private const AMPLITUDE_MINIMALE = 3 * 60;

    /**
     * L'amplitude qui contient tout juste les créneaux, arrondie à l'heure pleine des deux
     * côtés.
     *
     * Elle n'est jamais affichée telle quelle : c'est la borne à partir de laquelle
     * {@see self::plageEcran()} élargit à la journée entière. Elle vit à part parce que
     * c'est elle qui garantit qu'aucun créneau ne sort de la grille.
     *
     * @param list<CreneauOccupation> $creneaux
     */
    public function plage(array $creneaux): PlageHoraire
    {
        if ($creneaux === []) {
            return new PlageHoraire(self::DEBUT_JOURNEE, self::FIN_JOURNEE);
        }

        $debut = min(array_map(static fn (CreneauOccupation $c): int => $c->minutesDebut(), $creneaux));
        $fin = max(array_map(static fn (CreneauOccupation $c): int => $c->minutesFin(), $creneaux));

        // Heure pleine inférieure pour le haut, supérieure pour le bas : la première et la
        // dernière graduation sont ainsi de vraies heures, jamais un « 17h45 » orphelin.
        $debut = intdiv($debut, 60) * 60;
        $fin = (int) (ceil($fin / 60) * 60);

        if ($fin - $debut < self::AMPLITUDE_MINIMALE) {
            $fin = $debut + self::AMPLITUDE_MINIMALE;
        }

        return new PlageHoraire($debut, $fin);
    }

    /**
     * L'amplitude affichée : la journée entière, élargie s'il le faut.
     *
     * Fixe, pour que toute heure de la journée reste atteignable au glissé, et pour que
     * l'écran et le document dessinent la même semaine. Elle ne s'étend que dans un sens —
     * un créneau de 7h30 ou de 22h30 doit rester visible, et le rogner reviendrait à cacher
     * une réservation.
     *
     * @param list<CreneauOccupation> $creneaux
     */
    public function plageEcran(array $creneaux): PlageHoraire
    {
        $creneauxSeuls = $this->plage($creneaux);

        return new PlageHoraire(
            min(self::DEBUT_JOURNEE, $creneauxSeuls->debut),
            max(self::FIN_JOURNEE, $creneauxSeuls->fin),
        );
    }

    /**
     * La semaine entière : sept colonnes, y compris les jours sans rien — une colonne
     * absente ferait glisser les suivantes et le lecteur croirait lire un autre jour.
     *
     * @param list<CreneauOccupation> $creneaux
     * @param PlageHoraire|null       $plage amplitude imposée ; à défaut, celle des créneaux
     */
    public function grille(array $creneaux, ?PlageHoraire $plage = null): GrilleOccupation
    {
        $plage ??= $this->plage($creneaux);
        $colonnes = [];

        foreach (JourSemaine::cases() as $jour) {
            $duJour = array_values(array_filter(
                $creneaux,
                static fn (CreneauOccupation $c): bool => $c->getJour() === $jour,
            ));

            $colonnes[] = new ColonneJour($jour, $this->placer($duJour, $plage));
        }

        return new GrilleOccupation($plage, $colonnes);
    }

    /**
     * Place les créneaux d'une journée dans sa colonne.
     *
     * Deux créneaux au même horaire sont un cas **courant** — deux catégories se partagent
     * un terrain, ou deux terrains sont pris en même temps — et non une erreur à signaler.
     * Ils se partagent donc la largeur de la colonne : on regroupe ce qui se recouvre de
     * proche en proche, puis on range chaque créneau dans la première voie libre. La
     * largeur d'un bloc est celle de sa grappe, pas celle de la journée : un chevauchement
     * de 18h ne doit pas rétrécir le créneau isolé de 10h.
     *
     * @param list<CreneauOccupation> $creneaux d'un même jour
     *
     * @return list<BlocPlace>
     */
    private function placer(array $creneaux, PlageHoraire $plage): array
    {
        usort($creneaux, static fn (CreneauOccupation $a, CreneauOccupation $b) => [$a->minutesDebut(), $a->minutesFin()]
            <=> [$b->minutesDebut(), $b->minutesFin()]);

        $blocs = [];
        /** @var list<CreneauOccupation> $grappe */
        $grappe = [];
        $finGrappe = 0;

        foreach ($creneaux as $creneau) {
            // Un créneau qui commence après la fin de tout ce qui précède ouvre une grappe :
            // il ne recouvre rien, il reprend donc toute la largeur.
            if ($grappe !== [] && $creneau->minutesDebut() >= $finGrappe) {
                $blocs = [...$blocs, ...$this->repartir($grappe, $plage)];
                $grappe = [];
                $finGrappe = 0;
            }

            $grappe[] = $creneau;
            $finGrappe = max($finGrappe, $creneau->minutesFin());
        }

        return [...$blocs, ...$this->repartir($grappe, $plage)];
    }

    /**
     * Range une grappe de créneaux qui se recouvrent en voies verticales.
     *
     * @param list<CreneauOccupation> $grappe triée par début
     *
     * @return list<BlocPlace>
     */
    private function repartir(array $grappe, PlageHoraire $plage): array
    {
        if ($grappe === []) {
            return [];
        }

        /** @var list<int> $finDeVoie fin du dernier créneau posé dans chaque voie */
        $finDeVoie = [];
        /** @var list<int> $voieDe voie attribuée, dans l'ordre de la grappe */
        $voieDe = [];

        foreach ($grappe as $creneau) {
            $voie = null;

            foreach ($finDeVoie as $index => $fin) {
                if ($fin <= $creneau->minutesDebut()) {
                    $voie = $index;
                    break;
                }
            }

            if ($voie === null) {
                $voie = count($finDeVoie);
            }

            $finDeVoie[$voie] = $creneau->minutesFin();
            $voieDe[] = $voie;
        }

        $nombreDeVoies = count($finDeVoie);
        $blocs = [];

        foreach ($grappe as $rang => $creneau) {
            $blocs[] = new BlocPlace(
                $creneau,
                $plage->fraction($creneau->minutesDebut()),
                $creneau->duree() / $plage->duree(),
                $voieDe[$rang] / $nombreDeVoies,
                1 / $nombreDeVoies,
            );
        }

        return $blocs;
    }
}
