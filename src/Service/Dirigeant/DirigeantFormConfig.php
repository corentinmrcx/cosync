<?php declare(strict_types=1);

namespace App\Service\Dirigeant;

use App\Entity\Dirigeant;
use App\Entity\DocumentSignable;
use App\Service\Document\DocumentRequirementResolver;

/**
 * Ce que le composant Alpine du formulaire dirigeant a besoin de savoir : quelles étapes
 * afficher, et quels documents restent à signer.
 *
 * Un dirigeant déjà licencié a rempli ces champs dans son parcours de licencié : on ne
 * les lui redemande pas.
 */
final class DirigeantFormConfig
{
    public function __construct(
        private readonly DocumentRequirementResolver $documentResolver,
    ) {}

    /** @return array<string, mixed> passé tel quel à dirigeantForm() */
    public function pour(Dirigeant $dirigeant): array
    {
        $sansDossierLicencie = $dirigeant->getLicencie() === null;

        return [
            'needTaille' => $sansDossierLicencie && $dirigeant->getTailleHaut() === null,
            'needPhoto' => $sansDossierLicencie && $dirigeant->getAutorisationPhoto() === null,
            'needTransport' => $dirigeant->getVolontaireTransport() === null,
            'documents' => array_map(
                static fn (DocumentSignable $document): array => ['id' => $document->getId()],
                $this->documentResolver->manquantsPourDirigeant($dirigeant),
            ),
        ];
    }
}
