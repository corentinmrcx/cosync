<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Season;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Archive sur le Drive du club la grille d'occupation transmise à la mairie.
 *
 * Même doctrine que {@see PlanningDriveSync}, et pour la même raison : ni file d'attente,
 * ni reprise cron, ni chemin conservé en base. Ce dispositif existe pour les signatures
 * manuscrites, qui sont perdues pour toujours ; une grille se **régénère intégralement
 * depuis la base** en un clic. L'upload est donc synchrone, et un échec est **rendu** —
 * l'admin voit que l'archivage n'a pas eu lieu plutôt que de croire à un succès.
 *
 * `replaceAtPath` : il n'y a qu'une grille par saison, et la rééditer après un changement
 * d'organisation doit remplacer le fichier, pas en empiler des copies dont personne ne
 * saurait dire laquelle la mairie a reçue.
 */
final class OccupationDriveSync
{
    /** @var string[] */
    private const SEGMENTS = ['Plannings'];

    public function __construct(
        private readonly DriveUploaderService $driveUploader,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var')] private readonly string $varDir,
    ) {}

    /**
     * @param string $contenu binaire du PDF déjà rendu
     *
     * @return bool true si le fichier est sur Drive
     */
    public function archiver(string $contenu, string $nomFichier, Season $season): bool
    {
        // Fichier temporaire : DriveUploaderService dépose depuis un chemin local, et
        // var/pdfs/ est le point de reprise des signatures en attente, pas une corbeille.
        $chemin = $this->cheminTemporaire($nomFichier);

        if (file_put_contents($chemin, $contenu) === false) {
            $this->logger->error('Occupation des terrains : écriture du fichier temporaire impossible', ['chemin' => $chemin]);

            return false;
        }

        try {
            $driveId = $this->driveUploader->replaceAtPath(
                $chemin,
                $season->getLabel(),
                self::SEGMENTS,
                $nomFichier,
                sprintf('occupation terrains %s', $season->getLabel()),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Occupation des terrains : archivage Drive en échec', ['exception' => $e]);
            $driveId = null;
        } finally {
            @unlink($chemin);
        }

        return $driveId !== null;
    }

    private function cheminTemporaire(string $nomFichier): string
    {
        $repertoire = $this->varDir . '/tmp';

        if (!is_dir($repertoire)) {
            mkdir($repertoire, 0755, true);
        }

        return $repertoire . '/' . uniqid('occupation_', true) . '_' . $nomFichier;
    }
}
