<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\AttestationCle;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Archive l'attestation de remise de clés signée d'un détenteur.
 *
 * Les segments Drive n'ont pas suivi le renommage du module en « Clés » : ils
 * désignent des dossiers qui contiennent déjà des PDF réellement archivés, et les
 * renommer laisserait un dossier vide à côté des documents signés.
 *
 * @extends LocalFileDriveSync<AttestationCle>
 */
final class AttestationCleDriveSync extends LocalFileDriveSync
{
    /** @var string[] */
    private const SEGMENTS = ['Club house', 'Clés', 'Attestations de remise'];

    public function __construct(
        DriveUploader $driveUploader,
        EntityManagerInterface $em,
        private readonly DriveFilenameSanitizer $sanitizer,
    ) {
        parent::__construct($driveUploader, $em);
    }

    protected function cheminActuel(object $sujet): ?string
    {
        return $sujet->getDrivePath();
    }

    protected function enregistrerDriveId(object $sujet, string $driveId): void
    {
        $sujet->setDrivePath($driveId);
    }

    protected function destination(object $sujet): DriveDestination
    {
        $detenteur = $sujet->getDetenteur();

        return new DriveDestination(
            $sujet->getSeason()->getLabel(),
            self::SEGMENTS,
            sprintf(
                // La date de signature est dans le nom : une re-signature en cours de
                // saison (clé supplémentaire remise) ajoute un document, elle n'en
                // remplace pas un — les deux font foi à leur date.
                'Attestation_cle_%s_%s_%s.pdf',
                $this->sanitizer->sanitize($detenteur->getNom()),
                $this->sanitizer->sanitize($detenteur->getPrenom()),
                ($sujet->getSignedAt() ?? $sujet->getCreatedAt())->format('Y-m-d'),
            ),
            (string) $sujet->getUuid(),
        );
    }
}
