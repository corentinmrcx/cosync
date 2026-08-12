<?php declare(strict_types=1);

namespace App\Enum;

enum LicenceStatus: string
{
    case IMPORTED = 'imported';
    case LINK_SENT = 'link_sent';
    case FORM_COMPLETED = 'form_completed';
    case VALIDATED = 'validated';

    public function label(): string
    {
        return match ($this) {
            // Le licencié peut être arrivé par l'import comme par une création à la main :
            // ce statut ne dit rien de son origine, seulement que son lien n'est pas parti.
            self::IMPORTED => 'Lien non envoyé',
            self::LINK_SENT => 'Lien envoyé',
            self::FORM_COMPLETED => 'Formulaire complété',
            self::VALIDATED => 'Validé',
        };
    }
}
