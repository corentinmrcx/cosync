<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\Category;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

final class TeamSetupData
{
    public string $name = '';

    /** @var Collection<int, Category> */
    public Collection $categories;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
    }

    public function addCategory(Category $category): void
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }
    }

    public function removeCategory(Category $category): void
    {
        $this->categories->removeElement($category);
    }
}
