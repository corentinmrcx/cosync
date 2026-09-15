<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\DotationLigneCorrection;

/**
 * Les corrections qu'une ligne du suivi propose dans son menu « ⋯ ».
 *
 * Le menu et les formulaires de saisie lisent la même réponse : proposer une correction dont le
 * formulaire n'est pas rendu donnerait un article de menu qui n'ouvre rien.
 */
final class DotationLigneCorrections
{
    /** @param list<DotationLigneCorrection> $gestes */
    public function __construct(
        public readonly array $gestes,
    ) {}

    /** Accepte la valeur de l'enum : c'est ce que le template sait écrire. */
    public function propose(string $geste): bool
    {
        foreach ($this->gestes as $correction) {
            if ($correction->value === $geste) {
                return true;
            }
        }

        return false;
    }

    public function estVide(): bool
    {
        return $this->gestes === [];
    }
}
