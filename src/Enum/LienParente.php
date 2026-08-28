<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Lien entre la personne qui a réglé la licence et celle qui la porte.
 *
 * Impossible à déduire : `Licencie` ne connaît ni sexe ni civilité, et FootClubs ne
 * nomme qu'un seul parent — celui qui paie peut être l'autre. C'est donc le seul champ
 * de l'attestation que l'admin choisit à chaque fois.
 */
enum LienParente: string
{
    case SON_FILS = 'son_fils';
    case SA_FILLE = 'sa_fille';
    case SON_ENFANT = 'son_enfant';
    case LUI_MEME = 'lui_meme';
    case ELLE_MEME = 'elle_meme';

    public function label(): string
    {
        return match ($this) {
            self::SON_FILS => 'son fils',
            self::SA_FILLE => 'sa fille',
            self::SON_ENFANT => 'son enfant',
            self::LUI_MEME => 'lui-même',
            self::ELLE_MEME => 'elle-même',
        };
    }

    /**
     * Le destinataire est-il le licencié en personne ?
     *
     * L'attestation se rédige alors « au titre de sa licence », sans la clause
     * « concernant … » qui n'aurait aucun sens pour un adulte attestant de son
     * propre paiement.
     */
    public function estLeLicencie(): bool
    {
        return $this === self::LUI_MEME || $this === self::ELLE_MEME;
    }

    /**
     * Choix proposés à l'admin, dans l'ordre d'affichage.
     *
     * @return list<self>
     */
    public static function proposables(): array
    {
        return [self::SON_FILS, self::SA_FILLE, self::SON_ENFANT, self::LUI_MEME, self::ELLE_MEME];
    }
}
