<?php declare(strict_types=1);

namespace App\DTO;

use Symfony\Component\HttpFoundation\Request;

/**
 * Édition d'une équipe depuis l'écran « Équipes ». La cotisation n'en fait pas partie :
 * elle se règle sur l'écran « Cotisations », qui rassemble tous les montants de la saison.
 */
final class TeamEditData
{
    /** @param list<int> $categoryIds */
    public function __construct(
        public readonly ?string $nom,
        public readonly array $categoryIds,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $champs = $request->request->all('team');

        return new self(
            trim((string) ($champs['name'] ?? '')) ?: null,
            array_map('intval', (array) ($champs['categories'] ?? [])),
        );
    }
}
