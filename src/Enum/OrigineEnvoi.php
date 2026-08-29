<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Ce qui a fait partir un mail.
 *
 * Distinct de l'utilisateur qui l'a déclenché ({@see \App\Entity\EnvoiMail::$declenchePar}) :
 * un envoi automatique n'a pas d'auteur, et un envoi admin en a un dont le compte peut
 * disparaître ensuite. L'historique affiche l'un ou l'autre.
 */
enum OrigineEnvoi: string
{
    case ADMIN = 'admin';
    case LICENCIE = 'licencie';
    case AUTOMATIQUE = 'automatique';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Admin',
            self::LICENCIE => 'Licencié',
            self::AUTOMATIQUE => 'Système',
        };
    }
}
