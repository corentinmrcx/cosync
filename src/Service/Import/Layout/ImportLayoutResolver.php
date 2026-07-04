<?php declare(strict_types=1);

namespace App\Service\Import\Layout;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Choisit le layout capable de lire un fichier d'après ses en-têtes. Les formats supportés ont
 * des colonnes distinctes, la détection est donc sans ambiguïté.
 */
final class ImportLayoutResolver
{
    /**
     * @param iterable<ImportLayoutInterface> $layouts
     */
    public function __construct(
        #[AutowireIterator('app.import_layout')] private readonly iterable $layouts,
    ) {}

    /**
     * @param array<string, int> $columns  en-tête normalisé => index de colonne
     */
    public function resolve(array $columns): ?ImportLayoutInterface
    {
        foreach ($this->layouts as $layout) {
            if ($layout->supports($columns)) {
                return $layout;
            }
        }

        return null;
    }
}
