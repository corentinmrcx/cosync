<?php declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Dirigeant;
use App\Entity\DocumentSignable;
use App\Entity\Licencie;
use App\Enum\DocumentCible;
use App\Repository\DirigeantRepository;
use App\Repository\DocumentSignableRepository;
use App\Repository\DocumentSignatureRepository;

/**
 * Quels documents restent à signer par une personne donnée ?
 *
 * Source de vérité unique du parcours public comme des écrans d'administration :
 * l'ajout d'un document en cours de saison rend automatiquement incomplets les
 * dossiers concernés, sans qu'aucune donnée n'ait à être recalculée.
 */
final class DocumentRequirementResolver
{
    public function __construct(
        private readonly DocumentSignableRepository $documentRepo,
        private readonly DocumentSignatureRepository $signatureRepo,
        private readonly DirigeantRepository $dirigeantRepo,
    ) {}

    /**
     * Documents attendus d'un dirigeant, signés ou non, dans l'ordre des étapes.
     *
     * @return DocumentSignable[]
     */
    public function attendusPourDirigeant(Dirigeant $dirigeant): array
    {
        $documents = $this->documentRepo->findActifsByCible($dirigeant->getSeason(), DocumentCible::DIRIGEANT);

        return array_values(array_filter(
            $documents,
            static fn (DocumentSignable $doc): bool => $doc->concerne($dirigeant),
        ));
    }

    /**
     * Documents qu'il reste à faire signer à un dirigeant.
     *
     * @return DocumentSignable[]
     */
    public function manquantsPourDirigeant(Dirigeant $dirigeant): array
    {
        $signes = $this->idsSignesParDirigeant($dirigeant);

        return array_values(array_filter(
            $this->attendusPourDirigeant($dirigeant),
            static fn (DocumentSignable $doc): bool => !in_array($doc->getId(), $signes, true),
        ));
    }

    /**
     * Documents attendus d'un licencié. Aucun ciblage fin ici : un document destiné
     * aux licenciés s'adresse à toute la saison.
     *
     * @return DocumentSignable[]
     */
    public function attendusPourLicencie(Licencie $licencie): array
    {
        return $this->documentRepo->findActifsByCible($licencie->getSeason(), DocumentCible::LICENCIE);
    }

    /** @return DocumentSignable[] */
    public function manquantsPourLicencie(Licencie $licencie): array
    {
        $signes = $this->idsSignesParLicencie($licencie);

        return array_values(array_filter(
            $this->attendusPourLicencie($licencie),
            static fn (DocumentSignable $doc): bool => !in_array($doc->getId(), $signes, true),
        ));
    }

    /**
     * Dirigeants de la saison à qui ce document est demandé et qui ne l'ont pas signé.
     * Sert à relancer d'un coup les personnes concernées quand un document est ajouté
     * en cours de saison — leur dossier était complet, leur lien est donc consommé.
     *
     * @return Dirigeant[]
     */
    public function dirigeantsEnAttente(DocumentSignable $document): array
    {
        $signataires = $this->signatureRepo->dirigeantUuidsByDocument($document);

        return array_values(array_filter(
            $this->dirigeantRepo->findBySeason($document->getSeason()),
            static fn (Dirigeant $dirigeant): bool => $document->concerne($dirigeant)
                && !in_array((string) $dirigeant->getUuid(), $signataires, true),
        ));
    }

    /**
     * Signatures d'un dirigeant indexées par id de document, pour les écrans qui
     * affichent l'état document par document.
     *
     * @return array<int, \App\Entity\DocumentSignature>
     */
    public function signaturesParDocumentPourDirigeant(Dirigeant $dirigeant): array
    {
        $parDocument = [];

        foreach ($this->signatureRepo->findByDirigeant($dirigeant) as $signature) {
            $parDocument[$signature->getDocument()->getId()] = $signature;
        }

        return $parDocument;
    }

    /** @return array<int, \App\Entity\DocumentSignature> */
    public function signaturesParDocumentPourLicencie(Licencie $licencie): array
    {
        $parDocument = [];

        foreach ($this->signatureRepo->findByLicencie($licencie) as $signature) {
            $parDocument[$signature->getDocument()->getId()] = $signature;
        }

        return $parDocument;
    }

    /** @return int[] */
    private function idsSignesParDirigeant(Dirigeant $dirigeant): array
    {
        return array_map(
            static fn ($signature): int => $signature->getDocument()->getId(),
            $this->signatureRepo->findByDirigeant($dirigeant),
        );
    }

    /** @return int[] */
    private function idsSignesParLicencie(Licencie $licencie): array
    {
        return array_map(
            static fn ($signature): int => $signature->getDocument()->getId(),
            $this->signatureRepo->findByLicencie($licencie),
        );
    }
}
