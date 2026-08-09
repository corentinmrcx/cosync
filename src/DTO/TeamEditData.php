<?php declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\HttpFoundation\Request;

final class TeamEditData
{
    /** @param list<int> $categoryIds */
    public function __construct(
        public readonly ?string $nom,
        public readonly ?int $cotisation,
        public readonly array $categoryIds,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $champs = $request->request->all('team');
        $cotisation = trim((string) ($champs['cotisation'] ?? ''));

        return new self(
            trim((string) ($champs['name'] ?? '')) ?: null,
            $cotisation === '' ? null : (int) $cotisation,
            array_map('intval', (array) ($champs['categories'] ?? [])),
        );
    }
}
