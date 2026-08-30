<?php declare(strict_types=1);

namespace App\Service\Planning\Import;

use App\DTO\Planning\MatchImporteData;
use App\DTO\Planning\PlanningCollageApercu;

/**
 * Lit un bloc de texte collé — un tableau, un mail du district, une liste tapée — et en
 * tire des matchs.
 *
 * **Une ligne = un match**, les champs séparés par une tabulation, un point-virgule ou
 * au moins deux espaces. La date et l'heure sont reconnues où qu'elles soient dans la
 * ligne ; ce qui reste devient, dans l'ordre, la catégorie, l'adversaire, la note.
 *
 * Deux règles qui tiennent la fiabilité de l'ensemble :
 *
 * - **Une ligne sans date reconnue n'est jamais devinée**, elle est rejetée et rendue
 *   telle quelle à l'aperçu. Un planning est un document distribué : une date inventée
 *   à partir d'une ligne mal lue enverrait des habitants au stade un jour sans match.
 * - **Le parseur n'enregistre rien.** Il rend un aperçu ; c'est l'admin qui valide.
 */
final class CollagePlanningParser
{
    /** Séparateurs de colonnes acceptés : tabulation, point-virgule, ou 2 espaces et plus. */
    private const SEPARATEURS = '/\t+|\s*;\s*|\s{2,}/';

    private const MOIS = [
        'janvier' => 1, 'janv' => 1, 'jan' => 1,
        'fevrier' => 2, 'fev' => 2, 'febr' => 2,
        'mars' => 3, 'mar' => 3,
        'avril' => 4, 'avr' => 4,
        'mai' => 5,
        'juin' => 6,
        'juillet' => 7, 'juil' => 7,
        'aout' => 8, 'aou' => 8,
        'septembre' => 9, 'sept' => 9, 'sep' => 9,
        'octobre' => 10, 'oct' => 10,
        'novembre' => 11, 'nov' => 11,
        'decembre' => 12, 'dec' => 12,
    ];

    public function analyser(string $texte, ?int $anneeParDefaut = null): PlanningCollageApercu
    {
        $matchs = [];
        $ignorees = [];

        foreach (preg_split('/\r\n|\r|\n/', $texte) ?: [] as $ligne) {
            $ligne = trim($ligne);

            if ($ligne === '') {
                continue;
            }

            $match = $this->analyserLigne($ligne, $anneeParDefaut);

            if ($match === null) {
                $ignorees[] = $ligne;

                continue;
            }

            $matchs[] = $match;
        }

        usort($matchs, static fn (MatchImporteData $a, MatchImporteData $b) => [$a->date, $a->heure ?? '99:99'] <=> [$b->date, $b->heure ?? '99:99']);

        return new PlanningCollageApercu($matchs, $ignorees);
    }

    private function analyserLigne(string $ligne, ?int $anneeParDefaut): ?MatchImporteData
    {
        $champs = array_values(array_filter(
            array_map('trim', preg_split(self::SEPARATEURS, $ligne) ?: []),
            static fn (string $c) => $c !== '',
        ));

        $date = null;
        $heure = null;
        $restants = [];

        foreach ($champs as $champ) {
            if ($date === null && ($trouvee = $this->lireDate($champ, $anneeParDefaut)) !== null) {
                $date = $trouvee;

                // La date peut être noyée dans un champ plus large (« Dim. 20 sept. 2026 » ou
                // « 20/09/2026 15h00 » collés ensemble) : on retire ce qui a été consommé et
                // on rend le reste au tri des colonnes.
                $reste = $this->retirerDate($champ);

                if ($reste !== '') {
                    $champ = $reste;
                } else {
                    continue;
                }
            }

            if ($heure === null && ($trouvee = $this->lireHeure($champ)) !== null) {
                $heure = $trouvee;
                $reste = trim((string) preg_replace('/\b\d{1,2}\s*[:hH]\s*\d{2}\b/u', '', $champ));

                if ($reste === '') {
                    continue;
                }

                $champ = $reste;
            }

            $restants[] = $champ;
        }

        if ($date === null) {
            return null;
        }

        $categorie = $this->nettoyerCategorie(array_shift($restants));

        if ($categorie === null) {
            // Une date sans rien pour la qualifier ne fait pas un match imprimable.
            return null;
        }

        return new MatchImporteData(
            date: $date,
            heure: $heure,
            categorie: $categorie,
            adversaire: $this->nettoyerAdversaire(array_shift($restants)),
            note: $restants === [] ? null : mb_substr(implode(' ', $restants), 0, 255),
        );
    }

    /** `14/03/2026`, `14-03-26`, `2026-03-14`, `14 mars 2026`, `Dim. 14 mars`. */
    private function lireDate(string $champ, ?int $anneeParDefaut): ?\DateTimeImmutable
    {
        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $champ, $m) === 1) {
            return $this->construire((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('#\b(\d{1,2})[/.-](\d{1,2})(?:[/.-](\d{2,4}))?\b#', $champ, $m) === 1) {
            return $this->construire($this->annee($m[3] ?? null, $anneeParDefaut), (int) $m[2], (int) $m[1]);
        }

        $normalise = $this->sansAccents($champ);

        if (preg_match('/\b(\d{1,2})\s*(?:er)?\s+([a-z]+)\.?(?:\s+(\d{4}))?\b/', $normalise, $m) === 1) {
            $mois = self::MOIS[$m[2]] ?? null;

            if ($mois !== null) {
                return $this->construire($this->annee($m[3] ?? null, $anneeParDefaut), $mois, (int) $m[1]);
            }
        }

        return null;
    }

    private function retirerDate(string $champ): string
    {
        $sans = preg_replace(
            [
                '/\b\d{4}-\d{1,2}-\d{1,2}\b/',
                '#\b\d{1,2}[/.-]\d{1,2}(?:[/.-]\d{2,4})?\b#',
                '/\b\d{1,2}\s*(?:er)?\s+[A-Za-zÀ-ÿ]+\.?(?:\s+\d{4})?\b/u',
                // Le jour de la semaine ne porte aucune information une fois la date lue.
                '/\b(lun|mar|mer|jeu|ven|sam|dim)[a-zà-ÿ]*\.?/ui',
            ],
            '',
            $champ,
        );

        return trim((string) preg_replace('/\s{2,}/', ' ', (string) $sans), " \t-–—,:");
    }

    /** `09:30`, `9h30`, `15H00`. */
    private function lireHeure(string $champ): ?string
    {
        if (preg_match('/\b(\d{1,2})\s*[:hH]\s*(\d{2})\b/u', $champ, $m) !== 1) {
            return null;
        }

        $heures = (int) $m[1];
        $minutes = (int) $m[2];

        if ($heures > 23 || $minutes > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $heures, $minutes);
    }

    private function construire(int $annee, int $mois, int $jour): ?\DateTimeImmutable
    {
        if (!checkdate($mois, $jour, $annee)) {
            return null;
        }

        return (new \DateTimeImmutable())->setDate($annee, $mois, $jour)->setTime(0, 0);
    }

    /**
     * Une année absente prend celle de la saison de travail, jamais l'année courante :
     * un planning de janvier collé en décembre porterait sinon l'année qui s'achève.
     */
    private function annee(?string $brute, ?int $anneeParDefaut): int
    {
        if ($brute === null || $brute === '') {
            return $anneeParDefaut ?? (int) date('Y');
        }

        $annee = (int) $brute;

        return $annee < 100 ? 2000 + $annee : $annee;
    }

    private function nettoyerCategorie(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur, " \t-–—:");

        return $valeur === '' ? null : mb_substr($valeur, 0, 60);
    }

    /** Retire les « vs », « contre », « - » que les listes du district mettent devant l'adversaire. */
    private function nettoyerAdversaire(?string $valeur): ?string
    {
        $valeur = trim((string) preg_replace('/^\s*(vs\.?|contre|c\/|[-–—])\s*/iu', '', (string) $valeur));

        return $valeur === '' ? null : mb_substr($valeur, 0, 100);
    }

    private function sansAccents(string $valeur): string
    {
        return mb_strtolower(strtr($valeur, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'û' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c',
            'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
            'Î' => 'i', 'Ï' => 'i', 'Ô' => 'o', 'Ö' => 'o', 'Û' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Ç' => 'c',
        ]));
    }
}
