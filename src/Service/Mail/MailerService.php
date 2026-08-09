<?php declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Dirigeant;
use App\Entity\Licencie;
use App\Service\Payment\CotisationResolver;
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
    ) {}

    public function sendInscriptionLink(Licencie $licencie): void
    {
        $this->clubMailer->envoyer(
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
            $this->adresseDe($licencie),
            'Inscription bien reçue',
            'email/inscription_confirmation.html.twig',
            [
                'licencie' => $licencie,
                'montant' => $montant,
                'intentions' => $licencie->getDossierClub()?->getPaymentIntentions() ?? [],
                'libelleVirement' => $this->cotisationResolver->libelleVirement($licencie),
                'url' => $this->lienPublic('public_inscription_confirmation', $licencie->getUuid()),
                'documentsJoints' => count($retenus),
            ],
            $retenus,
        );
    }

    public function sendValidation(Licencie $licencie): void
    {
        $this->clubMailer->envoyer(
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
            $this->adresseDe($dirigeant),
            'Finalisez votre dossier dirigeant',
            'email/dirigeant_link.html.twig',
            [
                'dirigeant' => $dirigeant,
                'url' => $this->lienPublic('public_dirigeant_show', $dirigeant->getUuid()),
            ],
        );
    }

    public function sendAttestationCleLink(Dirigeant $dirigeant): void
    {
        $this->clubMailer->envoyer(
            $this->adresseDe($dirigeant),
            'Attestation de remise de clés à signer',
            'email/attestation_cle_link.html.twig',
            [
                'dirigeant' => $dirigeant,
                'url' => $this->lienPublic('public_attestation_cle_show', $dirigeant->getUuid()),
            ],
        );
    }

    private function adresseDe(Licencie|Dirigeant $personne): Address
    {
        return new Address((string) $personne->getEmail(), $personne->getNomPrenom());
    }

    private function lienPublic(string $route, \Symfony\Component\Uid\Uuid $uuid): string
    {
        return $this->urlGenerator->generate($route, ['uuid' => $uuid], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
