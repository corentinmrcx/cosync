<?php declare(strict_types=1);

namespace App\DTO;

final class ImportResultData
{
    public int $created = 0;
    public int $updated = 0;
    /** @var array<int, string> */
    public array $errors = [];

    public function addError(int $line, string $message): void
    {
        $this->errors[] = sprintf('Ligne %d : %s', $line, $message);
    }

    public function hasErrors(): bool
    {
        return count($this->errors) > 0;
    }
}
