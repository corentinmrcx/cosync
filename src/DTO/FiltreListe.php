<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Un filtre de la barre d'outils des listes (composant list-toolbar).
 */
final class FiltreListe
{
    /** @param list<array{value: int|string, label: string}> $options */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $allLabel,
        public readonly array $options,
        public readonly int|string|null $current,
    ) {}

    /**
     * Filtre alimenté par un enum : chaque cas devient une option.
     *
     * @param list<\BackedEnum> $cases
     */
    public static function depuisEnum(string $name, string $label, string $allLabel, array $cases, ?\BackedEnum $current): self
    {
        return new self(
            $name,
            $label,
            $allLabel,
            array_map(static fn (\BackedEnum $case): array => [
                'value' => $case->value,
                'label' => method_exists($case, 'label') ? $case->label() : (string) $case->value,
            ], $cases),
            $current?->value,
        );
    }

    /**
     * Filtre alimenté par des entités.
     *
     * @param list<object>                     $entites
     * @param callable(object): (int|string)   $valeur
     * @param callable(object): string         $libelle
     */
    public static function depuisEntites(
        string $name,
        string $label,
        string $allLabel,
        array $entites,
        callable $valeur,
        callable $libelle,
        int|string|null $current,
    ): self {
        return new self(
            $name,
            $label,
            $allLabel,
            array_map(static fn (object $entite): array => [
                'value' => $valeur($entite),
                'label' => $libelle($entite),
            ], $entites),
            $current,
        );
    }

    /** @param list<array{value: int|string, label: string}> $options */
    public static function fixe(string $name, string $label, string $allLabel, array $options, int|string|null $current): self
    {
        return new self($name, $label, $allLabel, $options, $current);
    }

    /**
     * Nombre de filtres réellement posés — l'indicateur affiché sur le bouton « Filtres ».
     *
     * @param list<self> $filtres
     */
    public static function compterActifs(array $filtres): int
    {
        return count(array_filter($filtres, static fn (self $filtre): bool => $filtre->current !== null && $filtre->current !== ''));
    }
}
