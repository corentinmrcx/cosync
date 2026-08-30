<?php declare(strict_types=1);

namespace App\Service\Ui;

/**
 * Dates en français, écrites à la main.
 *
 * ⚠️ Ne pas remplacer par `IntlDateFormatter`. L'image PHP embarque un ICU aux données
 * **réduites au seul anglais** : `IntlDateFormatter('fr_FR', …)` rend « Sunday 20
 * September 2026 » **sans lever la moindre erreur**. Sur un planning distribué dans les
 * boîtes aux lettres du village, la faute passerait la relecture et partirait à
 * l'impression. C'est le même piège que celui documenté pour `NumberFormatter::SPELLOUT`
 * dans MontantEnLettresFormatter.
 */
final class DateFrancaiseFormatter
{
    private const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    private const JOURS_COURTS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];

    private const MOIS = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    private const MOIS_COURTS = [
        1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
        'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.',
    ];

    /** « dimanche 20 septembre 2026 » */
    public function complete(\DateTimeInterface $date): string
    {
        return sprintf(
            '%s %s %s %d',
            self::JOURS[(int) $date->format('w')],
            $this->quantieme($date),
            self::MOIS[(int) $date->format('n')],
            (int) $date->format('Y'),
        );
    }

    /** « dimanche 20 septembre » — l'année se lit en tête du document, pas sur chaque ligne. */
    public function jourEtMois(\DateTimeInterface $date): string
    {
        return sprintf(
            '%s %s %s',
            self::JOURS[(int) $date->format('w')],
            $this->quantieme($date),
            self::MOIS[(int) $date->format('n')],
        );
    }

    /** « dim. 20 sept. » — pour les colonnes étroites d'un tableau. */
    public function court(\DateTimeInterface $date): string
    {
        return sprintf(
            '%s %s %s',
            self::JOURS_COURTS[(int) $date->format('w')],
            $this->quantieme($date),
            self::MOIS_COURTS[(int) $date->format('n')],
        );
    }

    /** « 20 septembre 2026 », sans le jour de la semaine. */
    public function sansJour(\DateTimeInterface $date): string
    {
        return sprintf(
            '%s %s %d',
            $this->quantieme($date),
            self::MOIS[(int) $date->format('n')],
            (int) $date->format('Y'),
        );
    }

    /**
     * « du 1er septembre au 30 septembre 2026 ».
     *
     * L'année n'est répétée que si la période l'enjambe — ce qui arrive vraiment : une
     * saison de football court de septembre à mai.
     */
    public function periode(\DateTimeInterface $du, \DateTimeInterface $au): string
    {
        $memeAnnee = $du->format('Y') === $au->format('Y');

        return sprintf(
            'du %s au %s',
            $memeAnnee
                ? sprintf('%s %s', $this->quantieme($du), self::MOIS[(int) $du->format('n')])
                : $this->sansJour($du),
            $this->sansJour($au),
        );
    }

    /** Le 1er du mois se dit « 1er », les autres se disent « 2 », « 3 »… */
    private function quantieme(\DateTimeInterface $date): string
    {
        $jour = (int) $date->format('j');

        return $jour === 1 ? '1er' : (string) $jour;
    }
}
