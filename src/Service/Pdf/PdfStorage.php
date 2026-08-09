<?php declare(strict_types=1);

namespace App\Service\Pdf;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Écrit les PDF signés dans var/pdfs/, où ils attendent leur archivage sur Drive.
 *
 * Ce répertoire est le point de reprise de app:drive-retry-upload : tant qu'un fichier
 * y est, il porte la seule copie d'une signature.
 */
final class PdfStorage
{
    private const REPERTOIRE = '/var/pdfs';

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function ecrire(string $nomFichier, string $contenu): string
    {
        $repertoire = $this->repertoire();

        if (!is_dir($repertoire)) {
            mkdir($repertoire, 0755, true);
        }

        $chemin = $repertoire . '/' . $nomFichier;
        file_put_contents($chemin, $contenu);

        return $chemin;
    }

    public function repertoire(): string
    {
        return $this->projectDir . self::REPERTOIRE;
    }
}
