<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Nature de la licence telle que déclarée par FootClubs (colonne « Nature » des exports).
 *
 * Distingue une première licence au club d'un renouvellement. Un muté (« Changement de club »)
 * est un nouveau licencié du point de vue du club, même s'il était déjà licencié ailleurs.
 */
enum NatureLicence: string
{
    case NOUVELLE_DEMANDE = 'nouvelle_demande';
    case CHANGEMENT_CLUB  = 'changement_club';
    case RENOUVELLEMENT   = 'renouvellement';

    public function label(): string
    {
        return match ($this) {
            self::NOUVELLE_DEMANDE => 'Nouvelle demande',
            self::CHANGEMENT_CLUB  => 'Changement de club',
            self::RENOUVELLEMENT   => 'Renouvellement',
        };
    }

    /** Nouveau au club : tout sauf un renouvellement. */
    public function estNouveau(): bool
    {
        return $this !== self::RENOUVELLEMENT;
    }

    /**
     * Normalise une valeur brute de la colonne « Nature » d'un export FootClubs.
     * Retourne null pour une valeur vide ou non reconnue : on ne devine pas.
     */
    public static function fromExport(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        $normalized = self::normalize($raw);
        if ($normalized === '') {
            return null;
        }

        return match (true) {
            str_contains($normalized, 'renouvellement')  => self::RENOUVELLEMENT,
            str_contains($normalized, 'changement')      => self::CHANGEMENT_CLUB,
            str_contains($normalized, 'mutation')        => self::CHANGEMENT_CLUB,
            str_contains($normalized, 'nouvelle demande') => self::NOUVELLE_DEMANDE,
            default                                      => null,
        };
    }

    /** Minuscules, sans accents, espaces réduits — pour comparer des libellés saisis à la main. */
    private static function normalize(string $raw): string
    {
        $lower = mb_strtolower(trim($raw), 'UTF-8');
        $ascii = strtr($lower, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);

        return (string) preg_replace('/\s+/u', ' ', $ascii);
    }
}
