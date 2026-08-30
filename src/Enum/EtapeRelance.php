<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Ce qui manque à une licence non soldée, et donc ce qu'on relance.
 *
 * Deux étapes, deux mails : redonner un lien à quelqu'un qui n'a rien rempli n'a rien à
 * voir avec rappeler un montant à quelqu'un dont le dossier est complet. Un message unique
 * demanderait à moitié la mauvaise chose aux deux moitiés de la liste.
 */
enum EtapeRelance: string
{
    case DOSSIER = 'dossier';
    case PAIEMENT = 'paiement';

    public function label(): string
    {
        return match ($this) {
            self::DOSSIER => 'Dossier à compléter',
            self::PAIEMENT => 'Paiement en attente',
        };
    }

    public function typeMail(): TypeMail
    {
        return match ($this) {
            self::DOSSIER => TypeMail::RELANCE_DOSSIER,
            self::PAIEMENT => TypeMail::RELANCE_PAIEMENT,
        };
    }
}
