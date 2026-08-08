<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Les trois rôles d'encadrement reconnus par le club. Référentiel fermé : contrairement à l'ancienne
 * entité DirigeantRole, aucun rôle ne peut plus être créé depuis l'UI ni depuis un import FootClubs.
 *
 * L'ordre de déclaration est l'ordre d'affichage (il remplace l'ancien sortOrder).
 */
enum DirigeantRole: string
{
    case RESPONSABLE_FOOT   = 'responsable_foot';
    case RESPONSABLE_EQUIPE = 'responsable_equipe';
    case DIRIGEANT          = 'dirigeant';

    public function label(): string
    {
        return match($this) {
            self::RESPONSABLE_FOOT   => 'Responsable foot',
            self::RESPONSABLE_EQUIPE => "Responsable d'équipe",
            self::DIRIGEANT          => 'Dirigeant',
        };
    }

    /** Qui rentre dans ce rôle — aide affichée à l'admin. */
    public function description(): string
    {
        return match($this) {
            self::RESPONSABLE_FOOT   => 'Membre du bureau du foot',
            self::RESPONSABLE_EQUIPE => "Coach principal d'une équipe",
            self::DIRIGEANT          => 'Bénévole occasionnel, coach adjoint, arbitre de touche, communication…',
        };
    }

    /** @return array<array{value: string, label: string}> Suggestions pour les combobox et filtres. */
    public static function options(): array
    {
        return array_map(
            static fn(self $role) => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }
}
