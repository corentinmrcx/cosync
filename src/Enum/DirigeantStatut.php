<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Où en est le dossier d'un dirigeant ?
 *
 * Statut **calculé**, jamais stocké : contrairement au licencié, dont le parcours passe par un
 * paiement et se fige donc en base, tout ce qui fait l'avancement d'un dirigeant est déjà écrit
 * ailleurs — le lien parti, le formulaire soumis, les documents signés, la licence validée à la
 * FFF. Le déduire plutôt que le dupliquer évite un état qui mentirait dès qu'on ajoute une
 * charte en cours de saison.
 *
 * @see \App\Service\Dirigeant\DirigeantStatutResolver pour l'ordre des règles
 */
enum DirigeantStatut: string
{
    case LIEN_NON_ENVOYE = 'lien_non_envoye';
    case LIEN_ENVOYE = 'lien_envoye';
    case DOCUMENT_A_SIGNER = 'document_a_signer';
    case A_VALIDER_FFF = 'a_valider_fff';
    case VALIDE = 'valide';
    case LICENCE_ADMINISTRATIVE = 'licence_administrative';

    public function label(): string
    {
        return match ($this) {
            self::LIEN_NON_ENVOYE => 'Lien non envoyé',
            self::LIEN_ENVOYE => 'Lien envoyé',
            // Le dossier était complet, un document a été ajouté depuis : la personne est à
            // relancer, ce n'est pas un formulaire jamais rempli.
            self::DOCUMENT_A_SIGNER => 'Document à signer',
            self::A_VALIDER_FFF => 'À valider sur FootClubs',
            self::VALIDE => 'Validé',
            self::LICENCE_ADMINISTRATIVE => 'Licence administrative',
        };
    }
}
