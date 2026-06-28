<?php declare(strict_types=1);

namespace App\DTO;

final class CategoryCreateData
{
    public string $code = '';
    public string $label = '';
    public bool $isEcoleFoot = false;
}
