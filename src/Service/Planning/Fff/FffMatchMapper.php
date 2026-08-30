<?php declare(strict_types=1);

namespace App\Service\Planning\Fff;

use App\DTO\Planning\MatchImporteData;

/**
 * Traduit une ligne de l'API fédérale en match du planning. Rien d'autre : aucune
 * décision d'enregistrement, aucun accès base.
 *
 * Les pièges sont dans les données réelles, pas dans le format :
 *
 * - `away` vaut **null** quand l'équipe est **exempte** de la journée. La FFF publie
 *   quand même une ligne, notre club en `home` — mais personne ne joue. C'est un
 *   non-match : l'inscrire ferait tondre la mairie pour rien et annoncerait aux
 *   habitants une rencontre qui n'aura pas lieu ;
 * - `terrain` vaut **null** tant que le district ne l'a pas affecté ;
 * - `time` s'écrit `15H30`, jamais `15:30` ;
 * - `date` arrive en ISO à minuit UTC : on en prend la **partie date**, sans conversion
 *   de fuseau. Convertir ferait reculer d'un jour tous les matchs en heure d'été.
 */
final class FffMatchMapper
{
    /**
     * Libellés lisibles par un habitant, à partir du code catégorie de la FFF.
     * Les codes jeunes (`U13`, `U17`…) se suffisent à eux-mêmes.
     */
    private const CATEGORIES = [
        'SEM' => 'Séniors',
        'SEF' => 'Séniors F',
        'VEM' => 'Vétérans',
        'VEF' => 'Vétérans F',
        'LOM' => 'Loisirs',
        'LOF' => 'Loisirs F',
    ];

    /**
     * Ne garde que les matchs joués **par le club, chez lui**, et les convertit.
     *
     * Le domicile se décide sur `home.club.cl_no`, pas sur le terrain : le terrain est
     * souvent null au moment où le calendrier paraît, et filtrer dessus ferait
     * disparaître du planning la moitié des rencontres à venir.
     *
     * @param list<array<string, mixed>> $lignes réponse brute de /api/clubs/{n}/matchs
     *
     * @return list<MatchImporteData>
     */
    public function domicile(array $lignes, int $clubNo): array
    {
        $matchs = [];

        foreach ($lignes as $ligne) {
            $home = $this->tableau($ligne['home'] ?? null);

            if ($home === null || $this->clubNoDe($home) !== $clubNo) {
                continue;
            }

            $match = $this->convertir($ligne, $home);

            if ($match !== null) {
                $matchs[] = $match;
            }
        }

        return $matchs;
    }

    /**
     * @param array<string, mixed> $ligne
     * @param array<string, mixed> $home
     */
    private function convertir(array $ligne, array $home): ?MatchImporteData
    {
        $date = $this->lireDate($ligne['date'] ?? null);
        $maNo = $ligne['ma_no'] ?? null;

        // Sans date ou sans identifiant fédéral, la ligne n'est ni imprimable ni
        // réconciliable d'une synchronisation à l'autre : on la laisse de côté.
        if ($date === null || !is_int($maNo)) {
            return null;
        }

        $away = $this->tableau($ligne['away'] ?? null);

        // Pas d'adversaire = équipe exempte de la journée. La FFF publie la ligne, mais
        // aucune rencontre n'a lieu et le terrain reste libre. Un plateau, lui, se saisit
        // à la main : c'est là — et là seulement — qu'un match sans adversaire est réel.
        if ($away === null) {
            return null;
        }

        $competition = $this->tableau($ligne['competition'] ?? null);
        $terrain = $this->tableau($ligne['terrain'] ?? null);

        return new MatchImporteData(
            date: $date,
            heure: $this->lireHeure($ligne['time'] ?? null),
            categorie: $this->lireCategorie($home, $competition),
            adversaire: $this->texte($away['short_name'] ?? null, 100),
            fffMaNo: $maNo,
            fffCompetition: $competition === null ? null : $this->texte($competition['name'] ?? null, 120),
            fffTerrain: $terrain === null ? null : $this->texte($terrain['name'] ?? null, 120),
        );
    }

    /** `2026-09-19T00:00:00+00:00` → 19/09/2026, sans jamais changer de fuseau. */
    private function lireDate(mixed $brute): ?\DateTimeImmutable
    {
        if (!is_string($brute) || $brute === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $brute, $m) !== 1) {
            return null;
        }

        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return (new \DateTimeImmutable())->setDate((int) $m[1], (int) $m[2], (int) $m[3])->setTime(0, 0);
    }

    /** `15H30` → `15:30`. Une heure absente ou aberrante rend null : le match tient sans. */
    private function lireHeure(mixed $brute): ?string
    {
        if (!is_string($brute) || preg_match('/(\d{1,2})\s*[hH:]\s*(\d{2})/', $brute, $m) !== 1) {
            return null;
        }

        $heures = (int) $m[1];
        $minutes = (int) $m[2];

        if ($heures > 23 || $minutes > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $heures, $minutes);
    }

    /**
     * Le libellé imprimé sur le flyer.
     *
     * La compétition prime sur le code catégorie quand elle nomme une classe d'âge :
     * la FFF classe en `U17` une équipe engagée en `U16 DISTRICT`, et c'est « U16 » que
     * le village reconnaît. Le code catégorie ne sert que de repli.
     *
     * Le numéro d'équipe n'est **pas** ajouté : sa signification varie d'un club à
     * l'autre, et un « Séniors 2 » faux sur un tract distribué vaut moins qu'un
     * « Séniors » un peu large. Un club à deux équipes de même catégorie détache la
     * ligne et la nomme lui-même.
     *
     * @param array<string, mixed>      $home
     * @param array<string, mixed>|null $competition
     */
    private function lireCategorie(array $home, ?array $competition): string
    {
        $nomCompet = is_string($competition['name'] ?? null) ? (string) $competition['name'] : '';

        if (preg_match('/\bU\s?(\d{1,2})\b/i', $nomCompet, $m) === 1) {
            return 'U' . $m[1];
        }

        $code = is_string($home['category_code'] ?? null) ? strtoupper((string) $home['category_code']) : '';

        if (isset(self::CATEGORIES[$code])) {
            return self::CATEGORIES[$code];
        }

        if (preg_match('/^U\d{1,2}$/', $code) === 1) {
            return $code;
        }

        $libelle = $this->texte($home['category_label'] ?? null, 60);

        return $libelle ?? ($code !== '' ? $code : 'Match');
    }

    /** @param array<string, mixed> $home */
    private function clubNoDe(array $home): ?int
    {
        $club = $this->tableau($home['club'] ?? null);
        $clubNo = $club['cl_no'] ?? null;

        return is_int($clubNo) ? $clubNo : null;
    }

    /** @return array<string, mixed>|null */
    private function tableau(mixed $valeur): ?array
    {
        if (!is_array($valeur) || $valeur === []) {
            return null;
        }

        /* @var array<string, mixed> $valeur */
        return $valeur;
    }

    private function texte(mixed $valeur, int $longueurMax): ?string
    {
        if (!is_string($valeur)) {
            return null;
        }

        $valeur = trim($valeur);

        return $valeur === '' ? null : mb_substr($valeur, 0, $longueurMax);
    }
}
