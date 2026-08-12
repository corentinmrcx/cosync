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
    public function __construct(
        #[Autowire('%app.pdf_dir%')] private readonly string $repertoire,
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
        return $this->repertoire;
    }

    /**
     * Supprime tous les PDF encore en attente d'archivage.
     *
     * Réservé à la purge du mode beta : hors de ce cas, un fichier présent ici porte la
     * seule copie d'une signature et ne doit jamais être effacé sans upload Drive réussi.
     *
     * @return int nombre de fichiers supprimés
     */
    public function viderRepertoire(): int
    {
        $repertoire = $this->repertoire();

        if (!is_dir($repertoire)) {
            return 0;
        }

        $supprimes = 0;
        foreach (glob($repertoire . '/*') ?: [] as $chemin) {
            if (is_file($chemin) && unlink($chemin)) {
                ++$supprimes;
            }
        }

        return $supprimes;
    }
}
