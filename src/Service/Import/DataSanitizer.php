<?php declare(strict_types=1);

namespace App\Service\Import;

final class DataSanitizer
{
    /**
     * La colonne "Nom, prénom" FootClubs contient les deux dans une seule cellule.
     * Règle : tout avant le premier espace = NOM (MAJUSCULES), tout après = Prénom (Capitalize).
     *
     * @return array{nom: string, prenom: string}
     */
    public function splitNomPrenom(string $raw): array
    {
        $raw   = trim($raw);
        $pos   = strpos($raw, ' ');

        if ($pos === false) {
            return [
                'nom'    => mb_strtoupper($raw, 'UTF-8'),
                'prenom' => '',
            ];
        }

        $nom    = mb_strtoupper(substr($raw, 0, $pos), 'UTF-8');
        $prenom = mb_convert_case(trim(substr($raw, $pos + 1)), MB_CASE_TITLE, 'UTF-8');

        return ['nom' => $nom, 'prenom' => $prenom];
    }

    /**
     * Extrait le code catégorie depuis la valeur brute FootClubs.
     *
     * Exemples :
     *   "U9 (- 9 ans)"      → "U9"
     *   "U10 F (- 10 ans F)" → "U10F"
     *   "Senior"             → "SENIOR"
     *   "Vétéran"            → "VETERAN"
     */
    public function sanitizeSousCategorie(string $raw): string
    {
        $raw = trim($raw);

        // Supprimer tout ce qui est entre parenthèses (y compris les parens)
        $code = (string) preg_replace('/\s*\(.*\)/', '', $raw);

        // Supprimer les espaces internes pour coller les suffixes (ex: "U10 F" → "U10F")
        $code = str_replace(' ', '', $code);

        // Normalisation
        $code = mb_strtoupper($code, 'UTF-8');
        $code = str_replace(['É', 'È', 'Ê', 'Ë'], 'E', $code);
        $code = str_replace(['À', 'Â'], 'A', $code);

        return $code;
    }

    public function sanitizeEmail(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        return mb_strtolower(trim($raw), 'UTF-8');
    }

    public function sanitizeNumLicence(string $raw): string
    {
        return trim($raw);
    }

    public function sanitizePhone(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $phone = preg_replace('/[\s\-\.]/', '', trim($raw)) ?? '';

        if ($phone === '') {
            return null;
        }

        // 9 chiffres sans zéro initial (export FootClubs) → +33X…
        if (strlen($phone) === 9 && preg_match('/^[67]/', $phone)) {
            $phone = '+33' . $phone;
        }

        // 0X… (10 chiffres) → +33X…
        if (str_starts_with($phone, '0') && strlen($phone) === 10) {
            $phone = '+33' . substr($phone, 1);
        }

        return $phone;
    }

    public function sanitizeDateNaissance(string $raw): \DateTimeImmutable
    {
        $raw = trim($raw);

        // Format FootClubs standard : JJ/MM/AAAA
        $dt = \DateTimeImmutable::createFromFormat('d/m/Y', $raw);

        if ($dt === false) {
            // Fallback pour d'autres formats éventuels
            $dt = new \DateTimeImmutable($raw);
        }

        return $dt->setTime(0, 0, 0);
    }
}
