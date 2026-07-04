<?php declare(strict_types=1);

namespace App\Service\Import;

final class DataSanitizer
{
    /**
     * Alias de codes catégories FFF vers le code CoSync équivalent.
     * Ex : « Senior U20 » (licence jeune rattachée aux séniors) → SENIOR.
     */
    private const CATEGORY_ALIASES = [
        'SENIORU20' => 'SENIOR',
    ];

    /**
     * La colonne "Nom, prénom" FootClubs contient les deux dans une seule cellule, sous la forme
     * « NOM Prénom » : le nom en MAJUSCULES (éventuellement composé, ex. « SAINT LOUIS »), le
     * prénom en Capitalize. On découpe donc à la bascule majuscules → minuscules plutôt qu'au
     * premier espace, ce qui préserve les noms composés (et fait coïncider le résultat avec
     * l'export dématérialisé, où nom et prénom sont déjà séparés).
     *
     * @return array{nom: string, prenom: string}
     */
    public function splitNomPrenom(string $raw): array
    {
        $raw = trim((string) preg_replace('/\s+/', ' ', $raw));

        if ($raw === '') {
            return ['nom' => '', 'prenom' => ''];
        }

        $tokens = explode(' ', $raw);
        if (count($tokens) === 1) {
            return ['nom' => mb_strtoupper($raw, 'UTF-8'), 'prenom' => ''];
        }

        // Le nom = suite initiale de tokens entièrement en majuscules.
        $nomCount = 0;
        foreach ($tokens as $token) {
            if (!$this->isUpperToken($token)) {
                break;
            }
            $nomCount++;
        }

        // Repli si aucun token majuscule en tête, ou si tout est en majuscules (prénom introuvable) :
        // le dernier token devient le prénom, le reste le nom.
        if ($nomCount === 0 || $nomCount === count($tokens)) {
            $nomCount = count($tokens) - 1;
        }

        return [
            'nom'    => mb_strtoupper(implode(' ', array_slice($tokens, 0, $nomCount)), 'UTF-8'),
            'prenom' => mb_convert_case(implode(' ', array_slice($tokens, $nomCount)), MB_CASE_TITLE, 'UTF-8'),
        ];
    }

    /** Un token est « nom » s'il contient une lettre et aucune minuscule (donc en capitales). */
    private function isUpperToken(string $token): bool
    {
        return preg_match('/\p{L}/u', $token) === 1
            && preg_match('/\p{Ll}/u', $token) === 0;
    }

    /**
     * Normalise un nom et un prénom déjà séparés (export dématérialisé, deux colonnes).
     * Nom en MAJUSCULES, Prénom en Capitalize.
     *
     * @return array{nom: string, prenom: string}
     */
    public function sanitizeSeparateNomPrenom(string $nom, string $prenom): array
    {
        return [
            'nom'    => mb_strtoupper(trim($nom), 'UTF-8'),
            'prenom' => mb_convert_case(trim($prenom), MB_CASE_TITLE, 'UTF-8'),
        ];
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

        return self::CATEGORY_ALIASES[$code] ?? $code;
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
