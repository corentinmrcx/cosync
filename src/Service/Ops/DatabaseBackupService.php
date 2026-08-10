<?php declare(strict_types=1);

namespace App\Service\Ops;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Produit un dump PostgreSQL compressé et purge les anciens.
 * Ne connaît ni Google Drive ni la console : l'orchestration est dans la commande.
 */
final class DatabaseBackupService
{
    /** Un dump gzippé de la base vide pèse déjà quelques Ko : en dessous, quelque chose a échoué. */
    private const TAILLE_MINIMALE_OCTETS = 1024;

    /** Dossier racine des sauvegardes sur le Drive, hors arborescence de saison. */
    public const DRIVE_ROOT = 'Sauvegardes';

    /** Un .sql.gz annoncé en application/pdf serait illisible depuis le Drive. */
    public const MIME_TYPE = 'application/gzip';

    /** pg_dump d'une base de club : quelques secondes. 10 min laisse une marge très large. */
    private const TIMEOUT_SECONDES = 600;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(POSTGRES_DB)%')] private readonly string $database,
        #[Autowire('%env(POSTGRES_USER)%')] private readonly string $user,
        #[Autowire('%env(POSTGRES_PASSWORD)%')] private readonly string $password,
        /** Nom du service Postgres dans le réseau Docker ; surchargeable pour un dump hors conteneur. */
        #[Autowire('%env(default::DATABASE_HOST)%')] private readonly ?string $host = null,
    ) {}

    /**
     * Crée un dump gzippé dans var/backups/ et retourne son chemin absolu.
     *
     * @throws \RuntimeException si pg_dump échoue ou produit un fichier suspect —
     *                           un backup silencieusement vide est pire que pas de backup
     */
    public function dump(): string
    {
        $directory = $this->backupDirectory();

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Impossible de créer le répertoire de sauvegarde : %s', $directory));
        }

        $path = sprintf('%s/backup_%s.sql.gz', $directory, (new \DateTimeImmutable())->format('Ymd_His'));

        // Compression native de pg_dump plutôt qu'un pipe vers gzip : un pipeline shell
        // renvoie le code de sortie de gzip, ce qui masquerait un pg_dump en échec et
        // laisserait un fichier tronqué passant pour une sauvegarde valable.
        $process = new Process(
            [
                'pg_dump',
                '--host=' . (($this->host !== null && $this->host !== '') ? $this->host : 'database'),
                '--username=' . $this->user,
                '--dbname=' . $this->database,
                '--no-owner',
                '--no-privileges',
                '--format=plain',
                '--compress=9',
                '--file=' . $path,
            ],
            null,
            ['PGPASSWORD' => $this->password],
            null,
            self::TIMEOUT_SECONDES,
        );

        $process->run();

        if (!$process->isSuccessful()) {
            @unlink($path);

            throw new \RuntimeException(sprintf('pg_dump a échoué (code %d) : %s', $process->getExitCode() ?? -1, trim($process->getErrorOutput()) ?: 'aucune sortie d\'erreur'));
        }

        $taille = file_exists($path) ? (int) filesize($path) : 0;

        if ($taille < self::TAILLE_MINIMALE_OCTETS) {
            @unlink($path);

            throw new \RuntimeException(sprintf('Le dump produit ne fait que %d octets : sauvegarde considérée comme invalide.', $taille));
        }

        return $path;
    }

    /**
     * Supprime les dumps locaux plus vieux que $joursRetention.
     *
     * @return string[] chemins supprimés
     */
    public function purgerAnciens(int $joursRetention = 30): array
    {
        $limite = (new \DateTimeImmutable(sprintf('-%d days', $joursRetention)))->getTimestamp();
        $supprimes = [];

        foreach ($this->listerDumps() as $path) {
            if (filemtime($path) < $limite && @unlink($path)) {
                $supprimes[] = $path;
            }
        }

        return $supprimes;
    }

    /**
     * Dumps présents localement, du plus récent au plus ancien.
     *
     * @return string[]
     */
    public function listerDumps(): array
    {
        $dumps = glob($this->backupDirectory() . '/backup_*.sql.gz') ?: [];
        rsort($dumps);

        return $dumps;
    }

    public function backupDirectory(): string
    {
        return $this->projectDir . '/var/backups';
    }

    /**
     * Emplacement du dump sur le Drive. Le découpage mensuel garde le dossier lisible :
     * une année de sauvegardes nightly fait 365 fichiers.
     *
     * @return string[]
     */
    public function driveSegments(?\DateTimeImmutable $date = null): array
    {
        return [self::DRIVE_ROOT, ($date ?? new \DateTimeImmutable())->format('Y-m')];
    }
}
