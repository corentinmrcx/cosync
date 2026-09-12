<?php declare(strict_types=1);

namespace App\Service\Referentiel;

use App\Entity\Fonction;
use App\Repository\FonctionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Écriture du référentiel des fonctions.
 *
 * Une fonction portée par au moins un dirigeant ne se supprime plus : la retirer laisserait
 * des fiches — et le récapitulatif remis à la mairie — sans la mention qu'elles portaient.
 * Le libellé, lui, se corrige librement : il n'est recopié nulle part, tout ce qui l'affiche
 * le relit ici. C'est la différence avec le référentiel des tailles, dont le libellé est
 * inscrit tel quel dans les dossiers et les mouvements de stock.
 */
final class FonctionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FonctionRepository $repository,
    ) {}

    /** @throws \DomainException si le libellé est vide ou déjà pris */
    public function creer(string $libelle, bool $porteEquipe): Fonction
    {
        $fonction = (new Fonction())
            ->setLibelle(trim($libelle))
            ->setPorteEquipe($porteEquipe);

        $this->assertLibelleLibre($fonction);
        $fonction->setPosition($this->repository->dernierePosition() + 1);

        $this->em->persist($fonction);
        $this->em->flush();

        return $fonction;
    }

    /** @throws \DomainException si le libellé est vide ou déjà pris par une autre fonction */
    public function modifier(Fonction $fonction, string $libelle, bool $porteEquipe): void
    {
        $fonction->setLibelle(trim($libelle));
        $fonction->setPorteEquipe($porteEquipe);

        $this->assertLibelleLibre($fonction);

        $this->em->flush();
    }

    /** @throws \DomainException si un dirigeant la porte */
    public function supprimer(Fonction $fonction): void
    {
        $portee = $this->repository->compterDirigeants($fonction);

        if ($portee > 0) {
            throw new \DomainException(sprintf('Impossible de supprimer « %s » : %d dirigeant%s la porte%s. Retirez-la de leur fiche d\'abord.', $fonction->getLibelle(), $portee, $portee > 1 ? 's' : '', $portee > 1 ? 'nt' : ''));
        }

        $this->em->remove($fonction);
        $this->em->flush();
    }

    /**
     * Réordonne le référentiel selon la liste d'identifiants reçue. Les fonctions absentes
     * de la liste sont reléguées à la suite : un onglet resté ouvert pendant qu'une fonction
     * était créée ailleurs ne doit en faire disparaître aucune.
     *
     * @param int[] $idsOrdonnes
     */
    public function reordonner(array $idsOrdonnes): void
    {
        $parId = [];
        foreach ($this->repository->findAllOrdered() as $fonction) {
            $parId[$fonction->getId()] = $fonction;
        }

        $position = 0;
        foreach ($idsOrdonnes as $id) {
            $fonction = $parId[$id] ?? null;
            if ($fonction === null) {
                continue;
            }

            $fonction->setPosition($position++);
            unset($parId[$id]);
        }

        foreach ($parId as $restante) {
            $restante->setPosition($position++);
        }

        $this->em->flush();
    }

    /** @throws \DomainException */
    private function assertLibelleLibre(Fonction $fonction): void
    {
        if ($fonction->getLibelle() === '') {
            throw new \DomainException('Le libellé d\'une fonction ne peut pas être vide.');
        }

        $existante = $this->repository->findOneByLibelle($fonction->getLibelle());

        if ($existante !== null && $existante !== $fonction) {
            throw new \DomainException(sprintf('La fonction « %s » existe déjà.', $fonction->getLibelle()));
        }
    }
}
