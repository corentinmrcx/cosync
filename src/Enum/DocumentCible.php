<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Population à qui un document signable est demandé.
 *
 * Contrairement à l'ancien ReglementAudience, cet enum ne porte ni titre, ni
 * libellé, ni texte : un document est une ligne de la table document_signable,
 * créée depuis l'admin. Seul le parcours public concerné reste une notion de code.
 */
enum DocumentCible: string
{
    case LICENCIE = 'licencie';
    case DIRIGEANT = 'dirigeant';

    public function label(): string
    {
        return match ($this) {
            self::LICENCIE  => 'Licenciés',
            self::DIRIGEANT => 'Dirigeants',
        };
    }
}
