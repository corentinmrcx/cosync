<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\AttestationPaiement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Archive l'attestation de paiement remise à un licencié ou à son parent.
 *
 * @extends LocalFileDriveSync<AttestationPaiement>
 */
final class AttestationPaiementDriveSync extends LocalFileDriveSync
{
    /** @var string[] */
    private const SEGMENTS = ['Attestations de paiement'];

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
        return new DriveDestination(
            $sujet->getSeason()->getLabel(),
            self::SEGMENTS,
            sprintf(
                // Datée : une réémission (destinataire corrigé, second parent) archive un
                // second document au lieu d'écraser celui déjà remis à un employeur.
                'attestation_paiement_%s_%s_%s.pdf',
                $this->sanitizer->sanitize($sujet->getLicencieNom()),
                $this->sanitizer->sanitize($sujet->getLicenciePrenom()),
                $sujet->getGeneratedAt()->format('Y-m-d'),
            ),
            (string) $sujet->getUuid(),
        );
    }
}
