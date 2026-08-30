<?php declare(strict_types=1);

namespace App\DTO;

use App\Enum\Civilite;
use App\Enum\LienParente;

/**
 * Ce que l'admin saisit pour émettre une attestation de paiement — et rien d'autre.
 *
 * Le montant, la date et le mode n'y figurent pas : ils sont dérivés des `Transaction`
 * du licencié. Une attestation qui annoncerait autre chose que ce que disent les
 * paiements enregistrés serait un faux, et c'est un document qui part chez un employeur.
 */
final class AttestationPaiementData
{
    public function __construct(
        public Civilite $destinataireCivilite = Civilite::MME,
        public string $destinatairePrenom = '',
        public string $destinataireNom = '',
        public LienParente $lienParente = LienParente::SON_ENFANT,
        /** Adresse d'envoi ; null = ne pas envoyer, générer et archiver seulement. */
        public ?string $email = null,
    ) {}
}
