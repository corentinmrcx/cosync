<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\DTO\Planning\PlanningPeriode;
use App\Entity\Season;
use App\Enum\PlanningFormat;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Archive sur le Drive du club le planning tel qu'il a été distribué.
 *
 * Volontairement **plus simple** que les autres archivages du projet : ni file d'attente
 * (`PendingUploadQueue`), ni reprise par `app:drive-retry-upload`, ni chemin local
 * conservé en base. Tout ce dispositif existe parce qu'une signature manuscrite perdue
 * est perdue pour toujours ; un planning, lui, se **régénère intégralement depuis la
 * base**. L'y faire entrer ajouterait une file, une colonne et une reprise cron pour
 * protéger un document reproductible en un clic.
 *
 * L'upload est donc synchrone et à la demande, et un échec est **rendu** — l'admin voit
 * que l'archivage n'a pas eu lieu et peut recommencer, au lieu de croire à un succès.
 *
 * `replaceAtPath` et non `uploadToPath` : régénérer le planning d'une même période
 * remplace le fichier au lieu d'en empiler des copies. C'est le même choix que pour le
 * récapitulatif des clés — un document vivant, pas une pièce qui fait foi à sa date.
 */
final class PlanningDriveSync
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
    public function archiver(string $contenu, string $nomFichier, Season $season, PlanningPeriode $periode, PlanningFormat $format): bool
    {
        // Fichier temporaire : DriveUploaderService dépose depuis un chemin local, et il
        // n'y a aucune raison de laisser traîner un planning dans var/pdfs/ — ce dossier
        // est le point de reprise des signatures en attente, pas une corbeille.
        $chemin = $this->cheminTemporaire($nomFichier);

        if (file_put_contents($chemin, $contenu) === false) {
            $this->logger->error('Planning : écriture du fichier temporaire impossible', ['chemin' => $chemin]);

            return false;
        }

        try {
            $driveId = $this->driveUploader->replaceAtPath(
                $chemin,
                $season->getLabel(),
                self::SEGMENTS,
                $nomFichier,
                sprintf('planning %s %s', $format->value, $periode->slug()),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Planning : archivage Drive en échec', ['exception' => $e]);
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

        return $repertoire . '/' . uniqid('planning_', true) . '_' . $nomFichier;
    }
}
