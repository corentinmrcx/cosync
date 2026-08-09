<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Autorisation restée sans réponse dans un dossier déjà soumis.
 *
 * Cas de figure : une question ajoutée au formulaire après coup. Plutôt que de faire
 * rejouer tout le parcours au licencié, on ne lui redemande que ces champs-là.
 */
enum AutorisationManquante: string
{
    case PHOTO = 'photo';
    case ACCIDENT = 'accident';
    case TRANSPORT_DIRIGEANTS = 'transport_dirigeants';
    case TRANSPORT_PARENTS = 'transport_parents';
    case VOLONTAIRE = 'volontaire';

    /** Nom du champ dans le formulaire de complétion. */
    public function champHttp(): string
    {
        return match ($this) {
            self::PHOTO => 'autorisation_photo',
            self::ACCIDENT => 'autorisation_accident',
            self::TRANSPORT_DIRIGEANTS => 'autorisation_transport_dirigeants',
            self::TRANSPORT_PARENTS => 'autorisation_transport_parents',
            self::VOLONTAIRE => 'volontaire_transport',
        };
    }

    /** Ne concerne que les jeunes : un majeur n'a pas d'autorisation parentale à donner. */
    public function reserveeAuxJeunes(): bool
    {
        return $this !== self::PHOTO;
    }

    /**
     * @param self[] $autorisations
     *
     * @return list<string> valeurs brutes, pour les vues et le JavaScript
     */
    public static function valeurs(array $autorisations): array
    {
        return array_map(static fn (self $autorisation): string => $autorisation->value, $autorisations);
    }
}
