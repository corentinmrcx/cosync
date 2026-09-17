<?php declare(strict_types=1);

namespace App\DTO\Justificatif;

/**
 * Un groupe de personnes à doter — une équipe, ou l'encadrement.
 *
 * C'est le multiplicateur du document : le kit dit ce qu'une personne reçoit, ce compte-là
 * dit combien de fois.
 */
final class JustificatifEffectif
{
    /** @param list<string> $kits noms des kits appliqués dans ce groupe */
    public function __construct(
        public readonly string $groupe,
        public readonly int $personnes,
        public readonly array $kits,
    ) {}
}
