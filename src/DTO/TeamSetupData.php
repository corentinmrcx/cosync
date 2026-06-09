<?php declare(strict_types=1);

namespace App\DTO;

use App\Entity\Category;

final class TeamSetupData
{
    public ?Category $category = null;

    /** Suffixe optionnel pour distinguer plusieurs équipes de même catégorie (A, B, 1, 2…) */
    public ?string $suffix = null;
}
