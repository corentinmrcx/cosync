<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\StockCategory;
use Doctrine\ORM\EntityManagerInterface;

final class StockCategoryService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function creer(StockCategory $category): void
    {
        $this->em->persist($category);
        $this->em->flush();
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
