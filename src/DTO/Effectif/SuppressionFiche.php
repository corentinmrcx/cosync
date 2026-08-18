<?php declare(strict_types=1);

namespace App\DTO\Effectif;

use App\Entity\Dirigeant;
use App\Entity\Licencie;
use Symfony\Component\Uid\Uuid;

/**
 * Verdict sur une fiche d'effectif qu'on cherche à supprimer, et la fiche elle-même.
 *
 * Les deux voyagent ensemble parce que l'écran de confirmation les affiche ensemble : il
 * annonce nom par nom ce qui va partir, et nom par nom ce qui est refusé avec son motif.
 * Une suppression qui se découvre après coup n'a pas de retour en arrière.
 */
final class SuppressionFiche
{
    private function __construct(
        public readonly Licencie|Dirigeant $fiche,
        public readonly bool $supprimable,
        /** Pourquoi la fiche ne peut pas être supprimée — null si elle l'est. */
        public readonly ?string $motifRefus,
    ) {}

    public static function autorisee(Licencie|Dirigeant $fiche): self
    {
        return new self($fiche, true, null);
    }

    public static function refusee(Licencie|Dirigeant $fiche, string $motif): self
    {
        return new self($fiche, false, $motif);
    }

    public function nomPrenom(): string
    {
        return $this->fiche->getNomPrenom();
    }

    public function uuid(): Uuid
    {
        return $this->fiche->getUuid();
    }
}
