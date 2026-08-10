<?php declare(strict_types=1);

namespace App\Service\Mail;

use Psr\Log\LoggerInterface;

/**
 * Ne retient que les fichiers réellement présents sur le disque, et renonce à tout joindre
 * au-delà du plafond : un mail rejeté par le serveur SMTP pour cause de taille ne
 * transporterait plus rien du tout, alors que les documents restent archivés sur le Drive.
 */
final class PiecesJointesFilter
{
    /** Au-delà, la plupart des serveurs SMTP rejettent le message. */
    private const TAILLE_MAX = 8 * 1024 * 1024;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, string> $fichiers chemin absolu => nom affiché
     *
     * @return array<string, string>
     */
    public function retenir(array $fichiers): array
    {
        $retenus = [];
        $total = 0;

        foreach ($fichiers as $chemin => $nom) {
            if (!is_file($chemin)) {
                $this->logger->warning('Mail : pièce jointe introuvable, ignorée ({chemin}).', ['chemin' => $chemin]);
                continue;
            }

            $total += (int) filesize($chemin);
            $retenus[$chemin] = $nom;
        }

        if ($total > self::TAILLE_MAX) {
            $this->logger->warning('Mail : {taille} octets de pièces jointes, envoi sans documents.', ['taille' => $total]);

            return [];
        }

        return $retenus;
    }
}
