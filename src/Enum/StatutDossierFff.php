<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Statut d'un dossier dans l'onglet « Licences dématérialisées » de FootClubs (colonne « Statut »).
 *
 * FootClubs fait foi sur l'avancement du dossier fédéral ; CoSync ne s'occupe que de la vie
 * interne du club. Un licencié n'a donc sa place ici qu'à partir du moment où il a rempli sa
 * part côté FFF — c'est-à-dire à « En attente signature club » et au-delà. Avant, rien ne dit
 * qu'il rejoindra le club ; après un rejet, on sait qu'il ne le rejoindra pas.
 *
 * L'ancien export « Éditions et extractions » n'a pas cette colonne : il ne contient que des
 * licences déjà signées, il n'y a donc rien à filtrer.
 */
enum StatutDossierFff: string
{
    case PRISE_DE_CONTACT = 'prise_de_contact';
    case EN_ATTENTE_SIGNATURE_CLUB = 'en_attente_signature_club';
    case EN_ATTENTE_VALIDATION_LIGUE = 'en_attente_validation_ligue';
    case ATTESTATION_LICENCE_CREEE = 'attestation_licence_creee';
    case REJETE = 'rejete';

    public function label(): string
    {
        return match ($this) {
            self::PRISE_DE_CONTACT => 'Prise de contact',
            self::EN_ATTENTE_SIGNATURE_CLUB => 'En attente signature club',
            self::EN_ATTENTE_VALIDATION_LIGUE => 'En attente validation ligue',
            self::ATTESTATION_LICENCE_CREEE => 'Attestation licence créée',
            self::REJETE => 'Rejeté',
        };
    }

    /**
     * Le licencié a-t-il fait sa part côté FFF ? Seul l'aval de la signature club l'atteste :
     * en deçà, le dossier peut encore être abandonné ; un rejet, lui, est définitif.
     */
    public function permetImport(): bool
    {
        return match ($this) {
            self::EN_ATTENTE_SIGNATURE_CLUB,
            self::EN_ATTENTE_VALIDATION_LIGUE,
            self::ATTESTATION_LICENCE_CREEE => true,
            self::PRISE_DE_CONTACT,
            self::REJETE => false,
        };
    }

    /** Pourquoi cette ligne n'est pas importée — affiché tel quel dans le rapport d'import. */
    public function motifExclusion(): string
    {
        return match ($this) {
            self::PRISE_DE_CONTACT => 'le licencié n\'a pas encore rempli son dossier FFF',
            self::REJETE => 'le dossier a été rejeté',
            default => '',
        };
    }

    /**
     * Normalise une valeur brute de la colonne « Statut ».
     * Retourne null pour une valeur vide ou non reconnue : on ne devine pas — et un statut
     * inconnu n'est jamais importé, c'est le sens du garde-fou.
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
            str_contains($normalized, 'prise de contact') => self::PRISE_DE_CONTACT,
            str_contains($normalized, 'signature club') => self::EN_ATTENTE_SIGNATURE_CLUB,
            str_contains($normalized, 'validation ligue') => self::EN_ATTENTE_VALIDATION_LIGUE,
            str_contains($normalized, 'attestation') => self::ATTESTATION_LICENCE_CREEE,
            str_contains($normalized, 'rejet') => self::REJETE,
            default => null,
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
