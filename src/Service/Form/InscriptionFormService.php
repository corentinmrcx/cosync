<?php declare(strict_types=1);

namespace App\Service\Form;

use App\DTO\InscriptionFormData;
use App\Entity\Licencie;
use App\Enum\LicenceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class InscriptionFormService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {}

    public function submit(Licencie $licencie, InscriptionFormData $data): void
    {
        $dossier = $licencie->getDossierClub();

        if ($dossier === null) {
            throw new \LogicException('Ce licencié n\'a pas de dossier club.');
        }

        $dossier->setTailleHaut($data->tailleHaut);
        $dossier->setTailleBas($data->tailleBas);
        $dossier->setPointure($data->pointure);
        $dossier->setAutorisationPhoto($data->autorisationPhoto);
        $dossier->setAutorisationTransportDirigeants($data->autorisationTransportDirigeants);
        $dossier->setAutorisationTransportParents($data->autorisationTransportParents);
        $dossier->setPaymentIntention($data->paymentIntention);
        $dossier->setIsSigned(true);
        $dossier->setSignatureDate(new \DateTimeImmutable());
        $dossier->setFormCompletedAt(new \DateTimeImmutable());
        $dossier->setStatus(LicenceStatus::FORM_COMPLETED);

        $this->saveSignatureTemp($licencie, $data->signatureData);

        // Le lien n'est plus utilisable après soumission
        $licencie->setFormTokenExpiresAt(null);

        $this->em->flush();
    }

    /** Sauvegarde la signature en fichier temp pour la génération PDF ultérieure */
    private function saveSignatureTemp(Licencie $licencie, string $signatureDataUrl): void
    {
        $base64 = (string) preg_replace('/^data:image\/\w+;base64,/', '', $signatureDataUrl);
        $imageData = base64_decode($base64, true);

        if ($imageData === false || $imageData === '') {
            return;
        }

        $dir = $this->projectDir . '/var/signatures';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($dir . '/' . $licencie->getUuid() . '.png', $imageData);
    }
}
