<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Season;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Archive sur le Drive du club le justificatif présenté au conseil avec un devis.
 *
 * Même doctrine que {@see OccupationDriveSync} : ni file d'attente, ni reprise cron, ni
 * chemin conservé en base. Le dispositif de reprise existe pour les signatures manuscrites,
 * perdues pour toujours ; ce document se régénère intégralement depuis la base. L'upload est
 * donc synchrone, et un échec est **rendu** — l'admin voit que l'archivage n'a pas eu lieu
 * plutôt que de croire une pièce classée alors qu'elle ne l'est pas.
 *
 * `replaceAtPath` sur un nom **daté du jour** : rééditer le justificatif après une correction
 * remplace celui du matin, mais la demande de novembre n'écrase pas celle de septembre. Les
 * deux ont été présentées, chacune avec son devis — écraser la première effacerait la pièce
 * qui justifie une commande déjà passée.
 */
final class JustificatifAchatDriveSync
{
    /** @var string[] */
    private const SEGMENTS = ['Dotations', 'Justificatifs d\'achat'];

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

        // L'écriture est mise en sourdine puis jugée sur son retour : sans cela, un var/tmp
        // que le process PHP ne possède pas laisse fuir un warning PHP jusqu'à la réponse,
        // qui corrompt le PDF renvoyé au navigateur et masque le vrai message.
        if ($chemin === null || @file_put_contents($chemin, $contenu) === false) {
            $this->logger->error('Justificatif d\'achat : écriture du fichier temporaire impossible', [
                'chemin' => $chemin ?? $this->varDir . '/tmp',
            ]);

            return false;
        }

        try {
            $driveId = $this->driveUploader->replaceAtPath(
                $chemin,
                $season->getLabel(),
                self::SEGMENTS,
                $nomFichier,
                sprintf('justificatif achat dotations %s', $season->getLabel()),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Justificatif d\'achat : archivage Drive en échec', ['exception' => $e]);
            $driveId = null;
        } finally {
            @unlink($chemin);
        }

        return $driveId !== null;
    }

    /** Null si le répertoire de travail est absent et ne peut pas être créé. */
    private function cheminTemporaire(string $nomFichier): ?string
    {
        $repertoire = $this->varDir . '/tmp';

        if (!is_dir($repertoire) && !@mkdir($repertoire, 0775, true) && !is_dir($repertoire)) {
            return null;
        }

        return $repertoire . '/' . uniqid('justificatif_', true) . '_' . $nomFichier;
    }
}
