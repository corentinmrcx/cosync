<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\AttestationCle;
use App\Entity\AttestationPaiement;
use App\Entity\Detenteur;
use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Enum\TypeMail;
use App\Service\Payment\CotisationResolver;
use App\Service\Referentiel\ClubSettingsService;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Les mails que le club envoie. Chaque méthode décrit un message ; l'expéditeur, la
 * redirection en mode bêta et l'envoi lui-même sont dans ClubMailer.
 */
final class MailerService
{
    public function __construct(
        private readonly ClubMailer $clubMailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CotisationResolver $cotisationResolver,
        private readonly PiecesJointesFilter $piecesJointes,
        private readonly ClubSettingsService $clubSettings,
    ) {}

    public function sendInscriptionLink(Licencie $licencie): void
    {
        $this->clubMailer->envoyer(
            TypeMail::INSCRIPTION_LINK,
            $licencie,
            $this->adresseDe($licencie),
            'Finalisez votre dossier',
            'email/inscription_link.html.twig',
            [
                'licencie' => $licencie,
                'url' => $this->lienPublic('public_inscription_show', $licencie->getUuid()),
            ],
        );
    }

    public function sendCompletionLink(Licencie $licencie): void
    {
        $this->clubMailer->envoyer(
            TypeMail::COMPLETION_LINK,
            $licencie,
            $this->adresseDe($licencie),
            'Une précision à apporter à votre dossier',
            'email/completion_link.html.twig',
            [
                'licencie' => $licencie,
                'url' => $this->lienPublic('public_inscription_completer', $licencie->getUuid()),
            ],
        );
    }

    /**
     * Demande de signature d'un document ajouté après l'inscription. Distinct du lien
     * de complétion : celui-là ne redemande aucune autorisation, il ne fait signer.
     */
    public function sendSignatureLink(Licencie $licencie): void
    {
        $this->clubMailer->envoyer(
            TypeMail::SIGNATURE_LINK,
            $licencie,
            $this->adresseDe($licencie),
            'Un document à signer',
            'email/signature_link.html.twig',
            [
                'licencie' => $licencie,
                'url' => $this->lienPublic('public_inscription_signer', $licencie->getUuid()),
            ],
        );
    }

    /**
     * Accusé de réception du formulaire, avec les instructions de paiement correspondant
     * au(x) mode(s) déclaré(s) et les documents signés en pièces jointes.
     *
     * C'est la seule trace écrite que le licencié reçoit tant qu'il n'a pas payé : le mail
     * de validation, lui, n'arrive qu'après encaissement — donc jamais pour qui ne règle pas.
     *
     * @param array<string, string> $pdfsJoints chemin local absolu => nom de fichier lisible
     */
    public function sendInscriptionConfirmation(Licencie $licencie, int $montant, array $pdfsJoints = []): void
    {
        if ($licencie->getEmail() === null) {
            return;
        }

        $retenus = $this->piecesJointes->retenir($pdfsJoints);

        $this->clubMailer->envoyer(
            TypeMail::CONFIRMATION,
            $licencie,
            $this->adresseDe($licencie),
            'Inscription bien reçue',
            'email/inscription_confirmation.html.twig',
            [
                'licencie' => $licencie,
                'montant' => $montant,
                'intentions' => $licencie->getDossierClub()?->getPaymentIntentions() ?? [],
                'precisionAutre' => $licencie->getDossierClub()?->getPaymentAutrePrecision(),
                'libelleVirement' => $this->cotisationResolver->libelleVirement($licencie),
                'url' => $this->lienPublic('public_inscription_confirmation', $licencie->getUuid()),
                'documentsJoints' => count($retenus),
            ],
            $retenus,
        );
    }

    /**
     * Annonce la boutique du club.
     *
     * Volontairement séparé du mail de confirmation : celui-ci porte le montant dû et les
     * instructions de paiement, et rien ne doit détourner le licencié de ce qu'il lui reste
     * à faire. Il ne part plus non plus dans la foulée d'une soumission : la boutique ouvre
     * quelques jours après les licences, l'annonce est un envoi groupé décidé par l'admin
     * depuis `/admin/boutique/annoncer`.
     *
     * Tant que la boutique n'est pas ouverte, aucun mail ne part.
     */
    public function sendBoutique(Licencie $licencie): void
    {
        $url = $this->clubSettings->get()->getBoutiqueUrlPublique();

        if ($url === null || $licencie->getEmail() === null) {
            return;
        }

        $this->clubMailer->envoyer(
            TypeMail::BOUTIQUE,
            $licencie,
            $this->adresseDe($licencie),
            'La boutique du club',
            'email/boutique.html.twig',
            [
                'licencie' => $licencie,
                'url' => $url,
            ],
        );
    }

    public function sendValidation(Licencie $licencie): void
    {
        $this->clubMailer->envoyer(
            TypeMail::VALIDATION,
            $licencie,
            $this->adresseDe($licencie),
            $licencie->getCategory()->isJeune()
                ? 'Licence de ' . $licencie->getPrenom() . ' validée'
                : 'Votre licence est validée',
            'email/validation.html.twig',
            ['licencie' => $licencie],
        );
    }

    public function sendDirigeantLink(Dirigeant $dirigeant): void
    {
        $this->clubMailer->envoyer(
            TypeMail::DIRIGEANT_LINK,
            $dirigeant,
            $this->adresseDe($dirigeant),
            'Finalisez votre dossier dirigeant',
            'email/dirigeant_link.html.twig',
            [
                'dirigeant' => $dirigeant,
                'url' => $this->lienPublic('public_dirigeant_show', $dirigeant->getUuid()),
            ],
        );
    }

    public function sendAttestationCleLink(AttestationCle $attestation): void
    {
        $detenteur = $attestation->getDetenteur();

        $this->clubMailer->envoyer(
            TypeMail::ATTESTATION_CLE,
            $detenteur,
            $this->adresseDe($detenteur),
            'Attestation de remise de clés à signer',
            'email/attestation_cle_link.html.twig',
            [
                'detenteur' => $detenteur,
                'season' => $attestation->getSeason(),
                'url' => $this->lienPublic('public_attestation_cle_show', $attestation->getUuid()),
            ],
        );
    }

    /**
     * Envoie une attestation de paiement à qui a réglé la licence.
     *
     * Le destinataire est passé explicitement plutôt que lu sur le licencié : le payeur
     * peut être un parent que FootClubs ne connaît pas, avec sa propre adresse mail.
     */
    public function sendAttestationPaiement(
        AttestationPaiement $attestation,
        string $email,
        string $cheminPdf,
        string $nomFichier,
    ): void {
        // Rattaché au licencié, pas au destinataire : c'est son dossier que l'attestation
        // concerne, et c'est sur sa fiche que l'envoi doit se lire. Le payeur peut être un
        // parent que CoSync ne connaît que par cette adresse.
        $this->clubMailer->envoyer(
            TypeMail::ATTESTATION_PAIEMENT,
            $attestation->getLicencie(),
            new Address($email, trim($attestation->getDestinatairePrenom() . ' ' . $attestation->getDestinataireNom())),
            'Votre attestation de paiement',
            'email/attestation_paiement.html.twig',
            [
                'attestation' => $attestation,
            ],
            [$cheminPdf => $nomFichier],
        );
    }

    private function adresseDe(Licencie|Dirigeant|Detenteur $personne): Address
    {
        return new Address((string) $personne->getEmail(), $personne->getNomPrenom());
    }

    private function lienPublic(string $route, \Symfony\Component\Uid\Uuid $uuid): string
    {
        return $this->urlGenerator->generate($route, ['uuid' => $uuid], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
