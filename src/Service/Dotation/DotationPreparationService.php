<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\Entity\DotationBesoin;
use App\Enum\DotationBesoinStatut;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Préparation d'une ligne de dotation : le sac est fait, il attend son destinataire.
 *
 * Rien ne sort du stock ici — un sac préparé est encore dans l'armoire du club, et la sortie
 * appartient à la remise ({@see DotationRemiseService}). Ce que le geste apporte n'est donc
 * pas un mouvement mais un **gel** : la taille cesse de se réaligner sur le dossier, la
 * répartition d'écoulement ne rearbitre plus le carton, le recalcul ne purge plus la ligne.
 * Sans lui, le suivi finissait par annoncer autre chose que ce que le sac contenait.
 *
 * Comme tout verrou du projet, il a sa sortie : `annulerPreparation()`.
 */
final class DotationPreparationService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Marque la ligne préparée.
     *
     * @throws \DomainException si l'article est déjà remis — il n'y a plus rien à préparer,
     *                          et repasser par là ferait reculer le suivi
     */
    public function preparer(DotationBesoin $besoin): void
    {
        if ($besoin->getStatut()->estRemis()) {
            throw new \DomainException('Cet article a déjà été remis : il n\'y a plus rien à préparer.');
        }

        if ($besoin->getStatut()->estPrepare()) {
            return;
        }

        $besoin->setStatut(DotationBesoinStatut::PREPARE);

        $this->em->flush();
    }

    /**
     * Relâche la préparation : la ligne repart « à donner » et l'automate la reprend en
     * charge — c'est bien l'effet recherché, on a défait le sac.
     */
    public function annulerPreparation(DotationBesoin $besoin): void
    {
        if (!$besoin->getStatut()->estPrepare()) {
            return;
        }

        $besoin->setStatut(DotationBesoinStatut::A_DONNER);

        $this->em->flush();
    }
}
