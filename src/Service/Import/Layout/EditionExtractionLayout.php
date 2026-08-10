<?php declare(strict_types=1);

namespace App\Service\Import\Layout;

use App\DTO\ImportRowData;
use App\Enum\ImportRowType;
use App\Service\Import\DataSanitizer;

/**
 * Ancien export FootClubs : Licenciés → Éditions et extractions → Édition licenciés (Complet).
 *
 * Colonnes : « Nom, prénom » (une cellule), « Type licence » (libre / dirigeant), « Sous catégorie ».
 * N'apparaissent ici que les licences déjà signées → envoi automatique du lien d'inscription.
 */
final class EditionExtractionLayout implements ImportLayoutInterface
{
    use ReadsColumnsTrait;

    private const COL_TYPE_LICENCE = 'type licence';
    private const COL_NOM_PRENOM = 'nom, prénom';
    private const COL_DATE_NAISSANCE = 'né(e) le';
    private const COL_SOUS_CATEGORIE = 'sous catégorie';
    private const COL_VOIE_RUE = 'voie-rue';
    private const COL_CODE_POSTAL = 'code postal';
    private const COL_BUREAU_DISTRIBUTEUR = 'bureau distributeur';
    private const COL_NUMERO_PERSONNE = 'numéro personne';
    private const COL_MOBILE_PERSONNEL = 'mobile personnel';
    private const COL_EMAIL_PRINCIPAL = 'email principal';
    private const COL_NATURE = 'nature';

    private const TYPE_LIBRE = 'libre';
    private const TYPE_DIRIGEANT = 'dirigeant';

    public function __construct(private readonly DataSanitizer $sanitizer) {}

    public function supports(array $columns): bool
    {
        return isset($columns[self::COL_TYPE_LICENCE], $columns[self::COL_NOM_PRENOM]);
    }

    public function map(array $row, array $columns): ImportRowData
    {
        $typeLicence = mb_strtolower(trim((string) $this->value($row, $columns, self::COL_TYPE_LICENCE)), 'UTF-8');

        $type = match ($typeLicence) {
            self::TYPE_LIBRE => ImportRowType::LICENCIE,
            self::TYPE_DIRIGEANT => ImportRowType::DIRIGEANT,
            default => ImportRowType::SKIP,
        };
        if ($type === ImportRowType::SKIP) {
            return ImportRowData::skipped();
        }

        $rawNomPrenom = trim((string) $this->value($row, $columns, self::COL_NOM_PRENOM));
        if ($rawNomPrenom === '') {
            return ImportRowData::skipped();
        }
        ['nom' => $nom, 'prenom' => $prenom] = $this->sanitizer->splitNomPrenom($rawNomPrenom);

        return new ImportRowData(
            $type,
            $nom,
            $prenom,
            $this->value($row, $columns, self::COL_NUMERO_PERSONNE),
            $this->value($row, $columns, self::COL_DATE_NAISSANCE),
            $this->value($row, $columns, self::COL_SOUS_CATEGORIE),
            $this->value($row, $columns, self::COL_EMAIL_PRINCIPAL),
            $this->value($row, $columns, self::COL_MOBILE_PERSONNEL),
            $this->value($row, $columns, self::COL_VOIE_RUE),
            $this->value($row, $columns, self::COL_CODE_POSTAL),
            $this->value($row, $columns, self::COL_BUREAU_DISTRIBUTEUR),
            $this->value($row, $columns, self::COL_NATURE),
        );
    }

    public function sendsEmailOnCreate(): bool
    {
        return true;
    }

    public function label(): string
    {
        return 'Éditions et extractions';
    }
}
