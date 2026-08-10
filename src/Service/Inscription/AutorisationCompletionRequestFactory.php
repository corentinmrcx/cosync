<?php declare(strict_types=1);

namespace App\Service\Inscription;

use App\DTO\AutorisationCompletionData;
use App\Enum\AutorisationManquante;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lit les réponses du formulaire de complétion, en ne réclamant que les autorisations
 * réellement manquantes — la liste est recalculée côté serveur, jamais reçue du client.
 */
final class AutorisationCompletionRequestFactory
{
    public function __construct(
        private readonly AttestationTransportRequestFactory $attestationFactory,
    ) {}

    /**
     * @param AutorisationManquante[] $manquantes
     *
     * @return AutorisationCompletionData|null null si une réponse attendue manque
     */
    public function fromRequest(Request $request, array $manquantes): ?AutorisationCompletionData
    {
        $reponses = [];

        foreach ($manquantes as $autorisation) {
            $reponse = $this->ouiNon($request->request->get($autorisation->champHttp()));

            if ($reponse === null) {
                return null;
            }

            $reponses[$autorisation->value] = $reponse;
        }

        $volontaire = $reponses[AutorisationManquante::VOLONTAIRE->value] ?? null;
        $attestation = null;

        // Se porter volontaire au transport engage : l'attestation part avec la réponse.
        if ($volontaire === true) {
            $attestation = $this->attestationFactory->fromRequest($request);

            if ($attestation === null) {
                return null;
            }
        }

        return new AutorisationCompletionData(
            $reponses[AutorisationManquante::PHOTO->value] ?? null,
            $reponses[AutorisationManquante::ACCIDENT->value] ?? null,
            $reponses[AutorisationManquante::TRANSPORT_DIRIGEANTS->value] ?? null,
            $reponses[AutorisationManquante::TRANSPORT_PARENTS->value] ?? null,
            $volontaire,
            $attestation,
        );
    }

    private function ouiNon(?string $brut): ?bool
    {
        return match ($brut) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }
}
