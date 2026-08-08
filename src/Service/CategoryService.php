<?php declare(strict_types=1);

namespace App\Service;

use App\DTO\CategoryCreateData;
use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Repository\LicencieRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;

class CategoryService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CategoryRepository $categoryRepo,
        private readonly LicencieRepository $licencieRepo,
        private readonly TeamRepository $teamRepo,
    ) {}

    public function create(CategoryCreateData $data): Category
    {
        $code = strtoupper(trim($data->code));

        if ($this->categoryRepo->findOneBy(['code' => $code]) !== null) {
            throw new \RuntimeException(sprintf('La catégorie "%s" existe déjà.', $code));
        }

        $category = new Category();
        $category->setCode($code);
        $category->setLabel(trim($data->label));
        $category->setIsEcoleFoot($data->isEcoleFoot);

        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    public function canDelete(Category $category): bool
    {
        return $this->licencieRepo->countByCategory($category) === 0;
    }

    public function delete(Category $category): void
    {
        $count = $this->licencieRepo->countByCategory($category);
        if ($count > 0) {
            throw new \RuntimeException(sprintf('Impossible de supprimer "%s" : utilisée par %d licencié(s).', $category->getCode(), $count));
        }

        foreach ($this->teamRepo->findByCategory($category) as $team) {
            $team->removeCategory($category);
        }

        $this->em->remove($category);
        $this->em->flush();
    }
}
