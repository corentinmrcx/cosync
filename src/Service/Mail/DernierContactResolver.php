<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\DTO\DernierContact;
use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Repository\EnvoiMailRepository;

/**
 * Quand le club a-t-il écrit à cette personne pour la dernière fois ?
 *
 * Un seul point de lecture pour les deux usages : la ligne affichée sur une fiche, et le
 * délai à partir duquel la relance automatique se déclenche. Les faire diverger reviendrait
 * à relancer quelqu'un que la fiche affiche comme contacté la veille.
 */
final class DernierContactResolver
{
    public function __construct(
        private readonly EnvoiMailRepository $envoiMailRepo,
    ) {}

    public function pour(Licencie|Dirigeant $personne, \DateTimeImmutable $maintenant = new \DateTimeImmutable()): ?DernierContact
    {
        $date = $this->envoiMailRepo->dernierEnvoi($personne);

        return $date === null ? null : new DernierContact($date, $maintenant);
    }
}
