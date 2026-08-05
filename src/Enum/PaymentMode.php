<?php declare(strict_types=1);

namespace App\Enum;

enum PaymentMode: string
{
    case CB_ONLINE = 'cb_online';
    case CHEQUE = 'cheque';
    case ESPECES = 'especes';
    case VIREMENT = 'virement';
    case PASS_SPORT = 'pass_sport';
    case CAF = 'caf';
    case ANCV = 'ancv';

    public function label(): string
    {
        return match($this) {
            self::CB_ONLINE => 'Carte bancaire en ligne (HelloAsso)',
            self::CHEQUE => 'Chèque',
            self::ESPECES => 'Espèces',
            self::VIREMENT => 'Virement bancaire',
            self::PASS_SPORT => 'Pass Sport',
            self::CAF => 'Chèque CAF',
            self::ANCV => 'ANCV',
        };
    }
}
