<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\DossierClub;

/**
 * Archive l'attestation de transport signée d'un licencié.
 *
 * @extends LocalFileDriveSync<DossierClub>
 */
final class AttestationDriveSync extends LocalFileDriveSync
{
    protected function cheminActuel(object $sujet): ?string
    {
        return $sujet->getAttestationTransportDriveId();
    }

    protected function enregistrerDriveId(object $sujet, string $driveId): void
    {
        $sujet->setAttestationTransportDriveId($driveId);
    }

    protected function destination(object $sujet): DriveDestination
    {
        $licencie = $sujet->getLicencie();

        return new DriveDestination(
            $licencie->getSeason()->getLabel(),
            ['Attestations Transport'],
            basename((string) $this->cheminActuel($sujet)),
            (string) $licencie->getUuid(),
        );
    }
}
