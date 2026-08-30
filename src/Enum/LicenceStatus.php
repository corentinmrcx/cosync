<?php declare(strict_types=1);

namespace App\Enum;

enum LicenceStatus: string
{
    case IMPORTED = 'imported';
    case LINK_SENT = 'link_sent';
    case FORM_COMPLETED = 'form_completed';
    case A_VALIDER_FFF = 'a_valider_fff';
    case VALIDATED = 'validated';

    public function label(): string
    {
        return match ($this) {
            // Le licencié peut être arrivé par l'import comme par une création à la main :
            // ce statut ne dit rien de son origine, seulement que son lien n'est pas parti.
            self::IMPORTED => 'Lien non envoyé',
            self::LINK_SENT => 'Lien envoyé',
            self::FORM_COMPLETED => 'Formulaire complété',
            self::A_VALIDER_FFF => 'À valider sur FootClubs',
            self::VALIDATED => 'Validé',
        };
    }

    /**
     * La cotisation est-elle soldée ?
     *
     * C'est **cette** question que posent la dotation, la sortie de stock et la
     * réconciliation HelloAsso — pas « la licence est-elle validée à la FFF ». Le club a
     * encore un geste à faire dans FootClubs après le solde ; le kit, lui, est dû dès que
     * l'argent est rentré. Passer ces tests sur `=== VALIDATED` reviendrait à suspendre le
     * droit au kit à un clic administratif sans rapport.
     */
    public function estSolde(): bool
    {
        return $this === self::A_VALIDER_FFF || $this === self::VALIDATED;
    }

    /**
     * Les mêmes statuts, pour un `IN` Doctrine.
     *
     * @return self[]
     */
    public static function soldes(): array
    {
        return [self::A_VALIDER_FFF, self::VALIDATED];
    }
}
