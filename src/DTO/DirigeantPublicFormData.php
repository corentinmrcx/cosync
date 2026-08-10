<?php declare(strict_types=1);

namespace App\DTO;

final class DirigeantPublicFormData
{
    public function __construct(
        // Tailles : null pour un dirigeant-joueur (reprises du dossier licencié)
        public readonly ?string $tailleHaut,
        public readonly ?string $tailleBas,
        public readonly ?string $pointure,
        // Droit à l'image : null pour un dirigeant-joueur (repris du dossier licencié)
        public readonly ?bool $autorisationPhoto,
        // Transport des licenciés : toujours demandé
        public readonly bool $volontaireTransport,
        // Attestation transport : présente uniquement si volontaireTransport === true
        public readonly ?AttestationTransportData $attestationTransport = null,
        /**
         * Signatures des documents à signer, indexées par id de DocumentSignable.
         * Vide si tous les documents attendus sont déjà signés.
         *
         * @var array<int, string> data URL base64 de la signature manuscrite
         */
        public readonly array $documentSignatures = [],
    ) {}
}
