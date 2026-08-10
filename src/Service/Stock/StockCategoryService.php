<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\StockCategory;
use App\Repository\StockCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;

final class StockCategoryService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockCategoryRepository $categoryRepository,
    ) {}

    /** La nouvelle catégorie se place en fin de liste : l'ordre se règle ensuite au glisser-déposer. */
    public function creer(StockCategory $category): void
    {
        $category->setPosition($this->prochainePosition());

        $this->em->persist($category);
        $this->em->flush();
    }

    /**
     * Réordonne les catégories selon la liste d'identifiants reçue.
     *
     * Les identifiants inconnus sont ignorés, et les catégories absentes de la liste sont
     * reléguées à la suite en conservant leur ordre : une liste partielle — onglet resté
     * ouvert pendant qu'une catégorie était créée ailleurs — ne doit jamais en faire
     * disparaître une de l'affichage.
     *
     * @param int[] $idsOrdonnes
     */
    public function reordonner(array $idsOrdonnes): void
    {
        $parId = [];
        foreach ($this->categoryRepository->findAllOrderedByPosition() as $category) {
            $parId[$category->getId()] = $category;
        }

        $position = 0;
        foreach ($idsOrdonnes as $id) {
            $category = $parId[$id] ?? null;
            if ($category === null) {
                continue;
            }

            $category->setPosition($position++);
            unset($parId[$id]);
        }

        foreach ($parId as $restante) {
            $restante->setPosition($position++);
        }

        $this->em->flush();
    }

    private function prochainePosition(): int
    {
        $existantes = $this->categoryRepository->findAllOrderedByPosition();
        if ($existantes === []) {
            return 0;
        }

        return end($existantes)->getPosition() + 1;
    }

    public function enregistrer(StockCategory $category): void
    {
        $this->em->flush();
    }

    public function supprimer(StockCategory $category): void
    {
        $this->em->remove($category);
        $this->em->flush();
    }
}
