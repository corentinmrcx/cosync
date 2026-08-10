<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Dirigeant;

/**
 * Archive l'attestation de transport signée d'un dirigeant.
 *
 * @extends LocalFileDriveSync<Dirigeant>
 */
final class DirigeantAttestationDriveSync extends LocalFileDriveSync
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
        return new DriveDestination(
            $sujet->getSeason()->getLabel(),
            ['Attestations Transport'],
            basename((string) $this->cheminActuel($sujet)),
            (string) $sujet->getUuid(),
        );
    }
}
