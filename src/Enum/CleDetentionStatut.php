<?php declare(strict_types=1);

namespace App\Enum;

enum CleDetentionStatut: string
{
    case DETENTEUR = 'detenteur';
    case SIGNATURE_MANQUANTE = 'signature_manquante';
    case HORS_EFFECTIF = 'hors_effectif';
    case RESTITUE = 'restitue';

    public function label(): string
    {
        return match ($this) {
            self::DETENTEUR => 'Détenteurs actuels',
            self::SIGNATURE_MANQUANTE => 'Attestation à signer',
            self::HORS_EFFECTIF => 'Détenteurs hors effectif',
            self::RESTITUE => 'Clés restituées',
        };
    }
}
