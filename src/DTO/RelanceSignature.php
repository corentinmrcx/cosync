<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\DocumentSignable;

/**
 * Une ligne de l'écran « Demander les signatures ».
 *
 * Le regroupement est **par personne**, jamais par document : quelqu'un à qui il manque
 * deux documents reçoit un seul mail, son parcours les lui présentant tous les deux.
 * Une relance par document lui en enverrait deux.
 *
 * La forme est commune aux joueurs et aux dirigeants — c'est le même geste, et l'écran
 * est le même. `rattachement` porte ce qui situe la personne : son équipe ou sa
 * catégorie pour un joueur, son rôle pour un dirigeant.
 */
final class RelanceSignature
{
    /** @param DocumentSignable[] $manquants */
    public function __construct(
        public readonly string $uuid,
        public readonly string $nomPrenom,
        public readonly ?string $email,
        public readonly string $rattachement,
        public readonly array $manquants,
    ) {}

    public function estJoignable(): bool
    {
        return $this->email !== null;
    }

    public function titresManquants(): string
    {
        return implode(', ', array_map(
            static fn (DocumentSignable $document): string => $document->getTitre(),
            $this->manquants,
        ));
    }
}
