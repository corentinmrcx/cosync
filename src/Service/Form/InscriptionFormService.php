<?php declare(strict_types=1);

namespace App\Service\Form;

use App\DTO\InscriptionFormData;
use App\Entity\Licencie;
use App\Enum\LicenceStatus;
use App\Service\Pdf\PdfGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class InscriptionFormService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PdfGeneratorService $pdfGenerator,
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

        $pdfPath = $this->pdfGenerator->generateReglementSigne($licencie, $data->signatureData);
        $dossier->setSignaturePath($pdfPath);

        // Le lien n'est plus utilisable après soumission
        $licencie->setFormTokenExpiresAt(null);

        $this->em->flush();

        // Nettoyage de l'ancienne signature temp si elle existe encore
        $this->deleteSignatureTemp((string) $licencie->getUuid());
    }

    private function deleteSignatureTemp(string $uuid): void
    {
        $path = $this->projectDir . '/var/signatures/' . $uuid . '.png';
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
