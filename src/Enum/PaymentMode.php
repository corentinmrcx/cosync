<?php declare(strict_types=1);

namespace App\Enum;

enum PaymentMode: string
{
    case CB_ONLINE = 'cb_online';
    case VIREMENT = 'virement';
    case CHEQUE = 'cheque';
    case ESPECES = 'especes';
    case PASS_SPORT = 'pass_sport';
    case AUTRE = 'autre';

    /*
     * Modes retirés du référentiel : le club ne les accepte plus et ils ne sont plus proposés
     * nulle part. Les cases restent déclarées parce que des paiements déjà encaissés les
     * portent en base — les supprimer rendrait ces lignes impossibles à relire.
     */
    case CAF = 'caf';
    case ANCV = 'ancv';

    public function label(): string
    {
        return match ($this) {
            self::CB_ONLINE => 'Carte bancaire',
            self::VIREMENT => 'Virement bancaire',
            self::CHEQUE => 'Chèque',
            self::ESPECES => 'Espèces',
            self::PASS_SPORT => "Pass'Sport",
            self::AUTRE => 'Autre',
            self::CAF => 'Chèque CAF',
            self::ANCV => 'ANCV / Chèques Vacances',
        };
    }

    /**
     * Modes encore acceptés par le club, dans l'ordre d'affichage.
     *
     * @return list<self>
     */
    public static function proposables(): array
    {
        return [
            self::CB_ONLINE,
            self::VIREMENT,
            self::CHEQUE,
            self::ESPECES,
            self::PASS_SPORT,
            self::AUTRE,
        ];
    }

    /**
     * Modes appelant une précision libre : n° de chèque, référence de virement, ou nature
     * exacte du paiement pour « Autre » (tickets MSA, coupon sport…).
     *
     * @return list<string> valeurs brutes, pour piloter un affichage côté client
     */
    public static function valeursAvecReference(): array
    {
        return array_map(
            fn (self $mode) => $mode->value,
            [self::VIREMENT, self::CHEQUE, self::AUTRE],
        );
    }
}
