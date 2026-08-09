<?php declare(strict_types=1);

namespace App\Service\Document;

use App\Entity\Dirigeant;
use App\Entity\Licencie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Récolte les signatures manuscrites postées par un formulaire public.
 *
 * La liste des documents attendus est recalculée ici, côté serveur : un identifiant envoyé
 * mais non attendu est ignoré, un document attendu mais non signé rejette la soumission.
 */
final class SignatureCollector
{
    /** Un canvas signé dépasse rarement 300 Ko ; au-delà, la requête est suspecte. */
    private const TAILLE_MAX = 2_800_000;

    public function __construct(
        private readonly DocumentRequirementResolver $documentResolver,
    ) {}

    /** @return array<int, string>|null null si une signature attendue manque ou est invalide */
    public function pourLicencie(Request $request, Licencie $licencie): ?array
    {
        return $this->collecter($request, $this->documentResolver->manquantsPourLicencie($licencie));
    }

    /** @return array<int, string>|null null si une signature attendue manque ou est invalide */
    public function pourDirigeant(Request $request, Dirigeant $dirigeant): ?array
    {
        return $this->collecter($request, $this->documentResolver->manquantsPourDirigeant($dirigeant));
    }

    /**
     * @param \App\Entity\DocumentSignable[] $attendus
     *
     * @return array<int, string>|null
     */
    private function collecter(Request $request, array $attendus): ?array
    {
        // Lecture défensive : une valeur scalaire doit être rejetée comme signature
        // manquante, pas provoquer une réponse 400 incompréhensible pour le signataire.
        $brut = $request->request->all()['signature_data'] ?? null;
        $soumises = is_array($brut) ? $brut : [];
        $retenues = [];

        foreach ($attendus as $document) {
            $signature = $soumises[$document->getId()] ?? null;

            if (!$this->estValide($signature)) {
                return null;
            }

            $retenues[$document->getId()] = $signature;
        }

        return $retenues;
    }

    private function estValide(mixed $signature): bool
    {
        return is_string($signature)
            && $signature !== ''
            && str_starts_with($signature, 'data:image/')
            && strlen($signature) <= self::TAILLE_MAX;
    }
}
