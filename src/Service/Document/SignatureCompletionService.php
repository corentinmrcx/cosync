<?php declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\DocumentSignable;
use App\Entity\Licencie;
use App\Repository\DocumentSignableRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Faire signer à un licencié un document qui n'existait pas quand il s'est inscrit.
 *
 * Le parcours public collecte les signatures avec le reste du dossier ; un document
 * ajouté en cours de saison arrive donc trop tard pour ceux qui ont déjà terminé —
 * leur formulaire est complet, leur lien est consommé, et rien ne le leur redemande.
 * C'est le seul chemin par lequel un dossier peut rester sans signature.
 *
 * Jumeau de {@see \App\Service\Inscription\AutorisationCompletionService}, qui rattrape
 * de la même façon les autorisations ajoutées après coup.
 */
final class SignatureCompletionService
{
    public function __construct(
        private readonly DocumentRequirementResolver $requirementResolver,
        private readonly DocumentSignableRepository $documentRepo,
        private readonly DocumentSignatureService $signatureService,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Documents restant à signer, ou rien tant que le formulaire initial n'a pas été
     * rempli : dans ce cas c'est le lien d'inscription qui s'impose, il collecte les
     * signatures avec le reste. Deux liens concurrents feraient signer deux fois.
     *
     * @return DocumentSignable[]
     */
    public function manquants(Licencie $licencie): array
    {
        $dossier = $licencie->getDossierClub();

        if ($dossier === null || $dossier->getFormCompletedAt() === null) {
            return [];
        }

        return $this->requirementResolver->manquantsPourLicencie($licencie);
    }

    public function hasMissing(Licencie $licencie): bool
    {
        return $this->manquants($licencie) !== [];
    }

    /**
     * Enregistre les signatures récoltées. Les ids ont déjà été repassés au crible des
     * documents réellement attendus par {@see SignatureCollector} : un id envoyé mais
     * non attendu n'arrive pas jusqu'ici.
     *
     * @param array<int, string> $signatures id du document => image de la signature
     */
    public function signer(Licencie $licencie, array $signatures): void
    {
        foreach ($signatures as $documentId => $signatureDataUrl) {
            $document = $this->documentRepo->find($documentId);

            if ($document !== null) {
                $this->signatureService->signerParLicencie($document, $licencie, $signatureDataUrl);
            }
        }

        // Lien à usage unique, comme celui de complétion des autorisations.
        $licencie->setFormTokenExpiresAt(null);
        $this->em->flush();
    }
}
