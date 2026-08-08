<?php declare(strict_types=1);

namespace App\Service\Import\Layout;

use App\DTO\ImportRowData;
use App\Enum\ImportRowType;
use App\Service\Import\DataSanitizer;

/**
 * Nouvel export FFF : Licences → Dématérialisées.
 *
 * Contient TOUTES les licences, y compris non signées (statuts « Prise de contact »…). C'est le
 * format qui permet de collecter les données club avant la signature. Colonnes « Nom » et « Prénom »
 * séparées, « Type » (Joueur / Dirigeant / Arbitre / Educateur), « Sous-catégorie » préfixée
 * (« Libre / U15 (- 15 ans) », « Dirigeant / Dirigeante »).
 *
 * Comme l'import concerne le roster complet, aucun mail n'est envoyé automatiquement : l'admin
 * envoie les liens depuis le tableau de bord.
 */
final class LicencesDematerialiseesLayout implements ImportLayoutInterface
{
    use ReadsColumnsTrait;

    private const COL_NOM = 'nom';
    private const COL_PRENOM = 'prénom';
    private const COL_NUMERO = 'numéro personne';
    private const COL_SOUS_CATEGORIE = 'sous-catégorie';
    private const COL_TYPE = 'type';
    private const COL_DATE_NAISSANCE = 'date de naissance';
    private const COL_EMAIL = 'email';
    private const COL_MOBILE = 'téléphone mobile';
    private const COL_ADRESSE = 'adresse 1';
    private const COL_CODE_POSTAL = 'code postal';
    private const COL_VILLE = 'ville';
    private const COL_NATURE = 'nature';

    private const TYPE_JOUEUR = 'joueur';

    public function __construct(private readonly DataSanitizer $sanitizer) {}

    public function supports(array $columns): bool
    {
        return isset(
            $columns[self::COL_TYPE],
            $columns[self::COL_SOUS_CATEGORIE],
            $columns[self::COL_NUMERO],
            $columns[self::COL_PRENOM],
        );
    }

    public function map(array $row, array $columns): ImportRowData
    {
        $rawNom = trim((string) $this->value($row, $columns, self::COL_NOM));
        $rawPrenom = trim((string) $this->value($row, $columns, self::COL_PRENOM));
        if ($rawNom === '' && $rawPrenom === '') {
            return ImportRowData::skipped();
        }
        ['nom' => $nom, 'prenom' => $prenom] = $this->sanitizer->sanitizeSeparateNomPrenom($rawNom, $rawPrenom);

        // CoSync ne connaît que deux cibles : Joueur → licencié, tout le reste → dirigeant.
        $typeValue = mb_strtolower(trim((string) $this->value($row, $columns, self::COL_TYPE)), 'UTF-8');
        $type = $typeValue === self::TYPE_JOUEUR ? ImportRowType::LICENCIE : ImportRowType::DIRIGEANT;

        $categoryOrRole = $this->stripFamilyPrefix($this->value($row, $columns, self::COL_SOUS_CATEGORIE));

        return new ImportRowData(
            $type,
            $nom,
            $prenom,
            $this->value($row, $columns, self::COL_NUMERO),
            $this->value($row, $columns, self::COL_DATE_NAISSANCE),
            $categoryOrRole,
            $this->value($row, $columns, self::COL_EMAIL),
            $this->value($row, $columns, self::COL_MOBILE),
            $this->value($row, $columns, self::COL_ADRESSE),
            $this->value($row, $columns, self::COL_CODE_POSTAL),
            $this->value($row, $columns, self::COL_VILLE),
            $this->value($row, $columns, self::COL_NATURE),
        );
    }

    /**
     * « Libre / U15 (- 15 ans) » → « U15 (- 15 ans) » ; « Dirigeant / Dirigeante » → « Dirigeante ».
     * La partie avant le premier « / » est la famille de licence, non pertinente pour CoSync.
     */
    private function stripFamilyPrefix(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $parts = explode('/', $raw, 2);

        return trim($parts[1] ?? $parts[0]);
    }

    public function sendsEmailOnCreate(): bool
    {
        return false;
    }

    public function label(): string
    {
        return 'Licences dématérialisées';
    }
}
