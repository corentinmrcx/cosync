<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\ImportRowType;

/**
 * Ligne d'import normalisée, indépendante du format source.
 *
 * Le layout produit cette DTO à partir des colonnes brutes ; `ImportService` la consomme
 * pour l'upsert. `nom`/`prenom` sont déjà normalisés (MAJUSCULES / Capitalize) car leur
 * découpage diffère selon le format. Les autres champs restent bruts : ils sont normalisés
 * par `DataSanitizer` au moment de l'upsert (logique partagée entre tous les formats).
 */
final class ImportRowData
{
    public function __construct(
        public readonly ImportRowType $type,
        public readonly string $nom,
        public readonly string $prenom,
        public readonly ?string $rawNumLicence,
        public readonly ?string $rawDateNaissance,
        /** Libellé catégorie (licencié) ou libellé rôle (dirigeant), avant normalisation. */
        public readonly ?string $rawCategoryOrRole,
        public readonly ?string $rawEmail,
        public readonly ?string $rawTelephone,
        public readonly ?string $rawVoieRue,
        public readonly ?string $rawCodePostal,
        public readonly ?string $rawVille,
        /** Colonne « Nature » FootClubs — absente de certains exports, d'où la valeur par défaut. */
        public readonly ?string $rawNature = null,
    ) {}

    public static function skipped(): self
    {
        return new self(ImportRowType::SKIP, '', '', null, null, null, null, null, null, null, null);
    }
}
