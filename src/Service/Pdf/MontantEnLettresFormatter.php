<?php declare(strict_types=1);

namespace App\Service\Pdf;

/**
 * « 120.00 » → « cent vingt euros ».
 *
 * Un montant en toutes lettres à côté du chiffre est l'usage de tout document financier :
 * il rend l'altération d'un chiffre visible.
 *
 * Écrit à la main plutôt que confié à `NumberFormatter::SPELLOUT` : l'image PHP embarque
 * un ICU aux données réduites au seul anglais (`ResourceBundle::getLocales('')` ne
 * contient pas « fr »), et la conversion retombait **silencieusement** sur
 * « one hundred twenty ». Une attestation en anglais serait partie chez un employeur sans
 * qu'aucune erreur ne soit levée. Cinquante lignes de règles françaises valent mieux
 * qu'une dépendance qui échoue sans le dire.
 */
final class MontantEnLettresFormatter
{
    /** @var array<int, string> */
    private const UNITES = [
        0 => 'zéro', 1 => 'un', 2 => 'deux', 3 => 'trois', 4 => 'quatre', 5 => 'cinq',
        6 => 'six', 7 => 'sept', 8 => 'huit', 9 => 'neuf', 10 => 'dix', 11 => 'onze',
        12 => 'douze', 13 => 'treize', 14 => 'quatorze', 15 => 'quinze', 16 => 'seize',
        17 => 'dix-sept', 18 => 'dix-huit', 19 => 'dix-neuf',
    ];

    /** @var array<int, string> */
    private const DIZAINES = [
        2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante',
    ];

    public function format(string $montant): string
    {
        // Passage par les centimes avant tout arrondi : (float) 120.29 * 100 vaut
        // 12028.999… et tronquerait le centime.
        $centimes = (int) round((float) $montant * 100);
        $euros = intdiv($centimes, 100);
        $reste = $centimes % 100;

        $texte = $this->epeler($euros) . ' ' . ($euros > 1 ? 'euros' : 'euro');

        if ($reste > 0) {
            $texte .= ' et ' . $this->epeler($reste) . ' ' . ($reste > 1 ? 'centimes' : 'centime');
        }

        return $texte;
    }

    public function epeler(int $nombre): string
    {
        if ($nombre < 0) {
            throw new \InvalidArgumentException('Un montant attesté ne peut pas être négatif.');
        }

        if ($nombre === 0) {
            return self::UNITES[0];
        }

        $morceaux = [];
        $restant = $nombre;

        // « mille » est invariable et ne se dit jamais « un mille » : d'où le traitement
        // à part des deux autres paliers, qui s'accordent.
        foreach ([1_000_000_000 => 'milliard', 1_000_000 => 'million'] as $palier => $mot) {
            $quotient = intdiv($restant, $palier);

            if ($quotient > 0) {
                $morceaux[] = $this->centaines($quotient, final: false) . ' ' . $mot . ($quotient > 1 ? 's' : '');
                $restant %= $palier;
            }
        }

        $milliers = intdiv($restant, 1000);
        if ($milliers > 0) {
            $morceaux[] = $milliers === 1 ? 'mille' : $this->centaines($milliers, final: false) . ' mille';
            $restant %= 1000;
        }

        if ($restant > 0) {
            $morceaux[] = $this->centaines($restant, final: true);
        }

        return implode(' ', $morceaux);
    }

    /**
     * 0 à 999.
     *
     * @param bool $final le groupe termine-t-il le nombre ? « cent » ne prend son s que
     *                    dans ce cas : « deux cents », mais « deux cent mille »
     */
    private function centaines(int $nombre, bool $final): string
    {
        $centaines = intdiv($nombre, 100);
        $reste = $nombre % 100;

        if ($centaines === 0) {
            return $this->dizaines($reste, $final);
        }

        $tete = $centaines === 1 ? 'cent' : self::UNITES[$centaines] . ' cent';

        if ($reste === 0) {
            return $centaines > 1 && $final ? $tete . 's' : $tete;
        }

        return $tete . ' ' . $this->dizaines($reste, $final);
    }

    /**
     * 0 à 99.
     *
     * @param bool $final même règle que pour « cent » : « quatre-vingts » prend son s en
     *                    fin de nombre, jamais devant « mille » — « quatre-vingt mille »
     */
    private function dizaines(int $nombre, bool $final): string
    {
        if ($nombre < 20) {
            return self::UNITES[$nombre];
        }

        $dizaine = intdiv($nombre, 10);
        $unite = $nombre % 10;

        // 70-79 et 90-99 se disent « soixante » / « quatre-vingt » suivis de 10 à 19.
        if ($dizaine === 7 || $dizaine === 9) {
            $base = $dizaine === 7 ? 'soixante' : 'quatre-vingt';
            $reste = $nombre - ($dizaine === 7 ? 60 : 80);

            // « soixante et onze », mais « quatre-vingt-onze » : le « et » ne se dit pas
            // sur les quatre-vingts.
            $lien = ($dizaine === 7 && $reste === 11) ? ' et ' : '-';

            return $base . $lien . self::UNITES[$reste];
        }

        if ($dizaine === 8) {
            // « quatre-vingts » seul, « quatre-vingt-trois » suivi — et jamais de « et ».
            if ($unite !== 0) {
                return 'quatre-vingt-' . self::UNITES[$unite];
            }

            return $final ? 'quatre-vingts' : 'quatre-vingt';
        }

        $base = self::DIZAINES[$dizaine];

        if ($unite === 0) {
            return $base;
        }

        return $unite === 1 ? $base . ' et un' : $base . '-' . self::UNITES[$unite];
    }
}
