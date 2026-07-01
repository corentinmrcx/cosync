<?php declare(strict_types=1);

namespace App\DTO;

/**
 * Réponses du licencié au formulaire de complétion des autorisations manquantes.
 * Chaque champ est null s'il n'était pas demandé (déjà renseigné précédemment).
 */
final class AutorisationCompletionData
{
    public function __construct(
        public readonly ?bool $autorisationPhoto,
        public readonly ?bool $autorisationAccident,
        public readonly ?bool $autorisationTransportDirigeants,
        public readonly ?bool $autorisationTransportParents,
        public readonly ?bool $volontaireTransport,
        public readonly ?AttestationTransportData $attestationTransport = null,
    ) {}
}
