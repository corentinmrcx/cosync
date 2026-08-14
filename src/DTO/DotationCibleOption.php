<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Un destinataire possible d'un kit, dans la forme qu'attend le composant multi-select :
 * une valeur postée et un libellé lisible. L'id peut être un entier d'équipe, un UUID de
 * licencié ou un slug de rôle — le sélecteur ne fait pas la différence.
 */
final class DotationCibleOption
{
    public function __construct(
        public readonly string $value,
        public readonly string $label,
    ) {}
}
