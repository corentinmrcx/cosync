<?php declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Dirigeant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Archive l'attestation de remise de clés signée d'un dirigeant.
 *
 * @extends LocalFileDriveSync<Dirigeant>
 */
final class DirigeantAttestationCleDriveSync extends LocalFileDriveSync
{
    /** @var string[] */
    private const SEGMENTS = ['Club house', 'Clés', 'Attestations de remise'];

    public function __construct(
        DriveUploaderService $driveUploader,
        EntityManagerInterface $em,
        private readonly DriveFilenameSanitizer $sanitizer,
    ) {
        parent::__construct($driveUploader, $em);
    }

    protected function cheminActuel(object $sujet): ?string
    {
        return $sujet->getAttestationCleSignePath();
    }

    protected function enregistrerDriveId(object $sujet, string $driveId): void
    {
        $sujet->setAttestationCleSignePath($driveId);
    }

    protected function destination(object $sujet): DriveDestination
    {
        return new DriveDestination(
            $sujet->getSeason()->getLabel(),
            self::SEGMENTS,
            sprintf(
                'Attestation_cle_%s_%s.pdf',
                $this->sanitizer->sanitize($sujet->getNom()),
                $this->sanitizer->sanitize($sujet->getPrenom()),
            ),
            (string) $sujet->getUuid(),
        );
    }
}
