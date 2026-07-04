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
        return match($this) {
            self::IMPORTED => 'Importé',
            self::LINK_SENT => 'Lien envoyé',
            self::FORM_COMPLETED => 'Formulaire complété',
            self::VALIDATED => 'Validé',
        };
    }
}
