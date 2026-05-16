<?php declare(strict_types=1);

namespace App\Enum;

enum LicenceStatus: string
{
    case LINK_SENT = 'link_sent';
    case FORM_COMPLETED = 'form_completed';
    case PAYMENT_CONFIRMED = 'payment_confirmed';
    case VALIDATED = 'validated';

    public function label(): string
    {
        return match($this) {
            self::LINK_SENT => 'Lien envoyé',
            self::FORM_COMPLETED => 'Formulaire complété',
            self::PAYMENT_CONFIRMED => 'Paiement confirmé',
            self::VALIDATED => 'Validé',
        };
    }
}
