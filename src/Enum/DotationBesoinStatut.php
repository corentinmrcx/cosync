<?php declare(strict_types=1);

namespace App\Enum;

enum DotationBesoinStatut: string
{
    case A_DONNER = 'a_donner';
    case DONNE = 'donne';

    public function label(): string
    {
        return match ($this) {
            self::A_DONNER => 'À donner',
            self::DONNE => 'Donné',
        };
    }
}
