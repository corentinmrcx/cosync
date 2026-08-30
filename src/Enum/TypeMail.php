<?php declare(strict_types=1);

namespace App\Enum;

/**
 * Le catalogue des mails que le club envoie.
 *
 * Chaque valeur est journalisée telle quelle dans {@see \App\Entity\EnvoiMail} : c'est
 * elle qui permet de compter les relances déjà parties et de nommer l'envoi dans
 * l'historique d'une fiche. Ajouter un mail au club, c'est donc ajouter un cas ici —
 * `ClubMailer::envoyer()` l'exige à l'appel, il n'y a pas de mail sans type.
 */
enum TypeMail: string
{
    case INSCRIPTION_LINK = 'inscription_link';
    case COMPLETION_LINK = 'completion_link';
    case SIGNATURE_LINK = 'signature_link';
    case CONFIRMATION = 'confirmation';
    case BOUTIQUE = 'boutique';
    case VALIDATION = 'validation';
    case DIRIGEANT_LINK = 'dirigeant_link';
    case ATTESTATION_CLE = 'attestation_cle';
    case ATTESTATION_PAIEMENT = 'attestation_paiement';

    /* — Relances — Deux types distincts, parce que ce ne sont pas deux versions du même
       message : l'un redonne un lien à remplir, l'autre rappelle un montant à régler. Les
       compter ensemble reste juste — c'est le plafond de relances qui les additionne. */
    case RELANCE_DOSSIER = 'relance_dossier';
    case RELANCE_PAIEMENT = 'relance_paiement';

    /** Libellé affiché dans l'historique d'une fiche, au passé : c'est un fait daté. */
    public function label(): string
    {
        return match ($this) {
            self::INSCRIPTION_LINK => 'Lien d\'inscription envoyé par email',
            self::COMPLETION_LINK => 'Demande de complément envoyée par email',
            self::SIGNATURE_LINK => 'Demande de signature envoyée par email',
            self::CONFIRMATION => 'Accusé de réception d\'inscription envoyé',
            self::BOUTIQUE => 'Annonce de la boutique envoyée',
            self::VALIDATION => 'Confirmation de licence validée envoyée',
            self::DIRIGEANT_LINK => 'Lien de formulaire dirigeant envoyé par email',
            self::ATTESTATION_CLE => 'Attestation de remise de clés à signer envoyée',
            self::ATTESTATION_PAIEMENT => 'Attestation de paiement envoyée',
            self::RELANCE_DOSSIER => 'Relance — dossier à compléter',
            self::RELANCE_PAIEMENT => 'Relance — paiement en attente',
        };
    }

    /**
     * Qui déclenche ce mail, dans le cas courant.
     *
     * Rendu par défaut plutôt qu'exigé à chaque appel : l'accusé de réception part
     * toujours parce que le licencié a soumis son formulaire, le lien d'inscription
     * toujours parce qu'un admin l'a décidé. `ClubMailer::envoyer()` accepte de le
     * surcharger pour les rares mails qui partent des deux façons.
     */
    public function origineParDefaut(): OrigineEnvoi
    {
        return match ($this) {
            // Sa soumission du formulaire public en est la cause directe.
            self::CONFIRMATION => OrigineEnvoi::LICENCIE,
            // Part au solde de la cotisation, que l'encaissement soit saisi par un admin
            // ou rattrapé par app:helloasso:sync-paiements : personne ne clique dessus.
            self::VALIDATION => OrigineEnvoi::AUTOMATIQUE,
            default => OrigineEnvoi::ADMIN,
        };
    }

    /**
     * Les types qui comptent pour le plafond de relances.
     *
     * @return self[]
     */
    public static function relances(): array
    {
        return [self::RELANCE_DOSSIER, self::RELANCE_PAIEMENT];
    }
}
