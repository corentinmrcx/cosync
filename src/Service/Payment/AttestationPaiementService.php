<?php declare(strict_types=1);

namespace App\Service\Payment;

use App\DTO\AttestationPaiementData;
use App\Entity\AttestationPaiement;
use App\Entity\Licencie;
use App\Enum\Civilite;
use App\Enum\LienParente;
use App\Repository\AttestationPaiementRepository;
use App\Repository\TransactionRepository;
use App\Service\Drive\PendingUploadQueue;
use App\Service\Mail\MailerService;
use App\Service\Pdf\AttestationPaiementPdfService;
use App\Service\Pdf\MontantEnLettresFormatter;
use App\Service\Referentiel\ClubSettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Émission des attestations de paiement de licence.
 *
 * Deux principes tiennent tout le reste :
 *
 * 1. **On n'atteste qu'une licence soldée.** Le verrou porte sur ce qui a réellement été
 *    encaissé, jamais sur le statut du dossier : `validate_manually` permet de valider une
 *    licence sans le moindre versement, et attester sur cette base produirait un document
 *    affirmant un paiement qui n'a pas eu lieu.
 * 2. **Le montant, la date et le mode ne se saisissent pas.** Ils sont dérivés des
 *    `Transaction` du licencié, puis figés sur l'attestation.
 */
final class AttestationPaiementService
{
    /** Tolérance de comparaison des sommes en euros — un centime d'écart d'arrondi ne bloque pas. */
    private const EPSILON = 0.005;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AttestationPaiementRepository $repository,
        private readonly TransactionRepository $transactionRepository,
        private readonly CotisationResolver $cotisationResolver,
        private readonly MontantEnLettresFormatter $montantEnLettres,
        private readonly AttestationPaiementPdfService $pdf,
        private readonly ClubSettingsService $clubSettings,
        private readonly PendingUploadQueue $uploadQueue,
        private readonly MailerService $mailer,
        private readonly Security $security,
    ) {}

    /**
     * Pourquoi l'émission est impossible, ou null si elle l'est.
     *
     * Rendre le motif plutôt qu'un booléen : l'écran doit pouvoir dire à l'admin ce qui
     * manque — un paiement, ou une configuration du club qu'il peut aller compléter.
     */
    public function motifBlocage(Licencie $licencie): ?string
    {
        if (!$this->clubSettings->get()->peutAttesterUnPaiement()) {
            return "L'identité de l'association et son signataire ne sont pas encore renseignés.";
        }

        $transactions = $this->transactionsDe($licencie);

        if ($transactions === []) {
            return 'Aucun paiement enregistré : il n\'y a rien à attester.';
        }

        if ($this->resteAPayer($licencie) > self::EPSILON) {
            return 'La licence n\'est pas soldée : une attestation ne peut porter que sur un paiement complet.';
        }

        return null;
    }

    public function peutEmettre(Licencie $licencie): bool
    {
        return $this->motifBlocage($licencie) === null;
    }

    /**
     * Pré-remplissage de l'écran de génération.
     *
     * La dernière attestation du licencié prime : la deuxième d'une saison vise presque
     * toujours le même payeur, et la ressaisir serait du travail refait. À défaut, on
     * part de l'identité du licencié lui-même — juste pour un adulte, à corriger pour un
     * mineur, dont FootClubs ne donne le nom d'aucun parent.
     */
    public function prefill(Licencie $licencie): AttestationPaiementData
    {
        $precedente = $this->repository->findDerniereParLicencie($licencie);

        if ($precedente !== null) {
            return new AttestationPaiementData(
                destinataireCivilite: $precedente->getDestinataireCivilite(),
                destinatairePrenom: $precedente->getDestinatairePrenom(),
                destinataireNom: $precedente->getDestinataireNom(),
                lienParente: $precedente->getLienParente(),
                email: $precedente->getEnvoyeeA() ?? $licencie->getEmail(),
            );
        }

        $majeur = !$licencie->getCategory()->isJeune();

        return new AttestationPaiementData(
            destinataireCivilite: Civilite::MME,
            destinatairePrenom: $majeur ? $licencie->getPrenom() : '',
            destinataireNom: $majeur ? $licencie->getNom() : '',
            lienParente: $majeur ? LienParente::LUI_MEME : LienParente::SON_ENFANT,
            email: $licencie->getEmail(),
        );
    }

    /**
     * Construit l'attestation sans la persister : le montant, la date et les modes sont
     * résolus ici, une fois, pour l'aperçu comme pour l'émission. Les deux montrent donc
     * exactement le même document.
     */
    public function composer(Licencie $licencie, AttestationPaiementData $data): AttestationPaiement
    {
        $transactions = $this->transactionsDe($licencie);
        $club = $this->clubSettings->get();

        $total = 0.0;
        $modes = [];
        $derniereDate = null;

        foreach ($transactions as $transaction) {
            $total += (float) $transaction->getMontant();

            if (!in_array($transaction->getMode(), $modes, true)) {
                $modes[] = $transaction->getMode();
            }

            if ($derniereDate === null || $transaction->getDatePaiement() > $derniereDate) {
                $derniereDate = $transaction->getDatePaiement();
            }
        }

        $montant = number_format($total, 2, '.', '');

        $attestation = (new AttestationPaiement())
            ->setLicencie($licencie)
            ->setSeason($licencie->getSeason())
            ->setLicencieNom($licencie->getNom())
            ->setLicenciePrenom($licencie->getPrenom())
            ->setDestinataireCivilite($data->destinataireCivilite)
            ->setDestinatairePrenom(trim($data->destinatairePrenom))
            ->setDestinataireNom(trim($data->destinataireNom))
            ->setLienParente($data->lienParente)
            ->setMontant($montant)
            ->setMontantEnLettres($this->montantEnLettres->format($montant))
            ->setDatePaiement($derniereDate ?? new \DateTimeImmutable())
            ->setModes($modes)
            // Le signataire est recopié, pas référencé : le club change de trésorier, une
            // attestation déjà remise continue de nommer celui qui l'a signée.
            ->setSignataireCivilite($club->getSignataireCivilite() ?? Civilite::M)
            ->setSignataireNom((string) $club->getSignataireNom())
            ->setSignataireQualite((string) $club->getSignataireQualite());

        foreach ($transactions as $transaction) {
            $attestation->addTransaction($transaction);
        }

        return $attestation;
    }

    /** Aperçu affiché dans l'écran de génération, sans rien enregistrer. */
    public function apercu(Licencie $licencie, AttestationPaiementData $data): string
    {
        return $this->pdf->rendu($this->composer($licencie, $data), apercu: true);
    }

    /**
     * Émet l'attestation : enregistrement, PDF, archivage Drive différé, et envoi si une
     * adresse est fournie. Rien ne part si `$data->email` est null — l'admin peut vouloir
     * imprimer et remettre en main propre.
     *
     * @throws \LogicException si la licence n'est pas en état d'être attestée
     */
    public function emettre(Licencie $licencie, AttestationPaiementData $data): AttestationPaiement
    {
        $motif = $this->motifBlocage($licencie);

        if ($motif !== null) {
            throw new \LogicException($motif);
        }

        $attestation = $this->composer($licencie, $data);
        $attestation->setGeneratedBy($this->utilisateurCourant());

        $this->em->persist($attestation);
        $this->em->flush();

        $cheminLocal = $this->pdf->generer($attestation);
        $attestation->setDrivePath($cheminLocal);
        $this->em->flush();

        // Le PDF est joint depuis var/pdfs avant que l'archivage Drive ne l'y supprime :
        // l'envoi précède donc la mise en file, jamais l'inverse.
        //
        // Un SMTP en panne ne doit pas emporter le document : il est déjà produit et
        // archivé, et l'admin peut le renvoyer depuis la fiche. L'appelant compare son
        // intention d'envoi à `estEnvoyee()` pour savoir quoi annoncer.
        if ($data->email !== null && $data->email !== '') {
            try {
                $this->envoyer($attestation, $data->email, $cheminLocal);
            } catch (\Throwable) {
                // L'attestation reste émise, `envoyeeLe` reste null.
            }
        }

        $this->uploadQueue->enqueueAttestationPaiement($attestation->getId());

        return $attestation;
    }

    /**
     * Renvoie une attestation déjà émise. Le PDF est régénéré depuis les valeurs figées :
     * une fois archivé, le fichier local a été supprimé.
     */
    public function renvoyer(AttestationPaiement $attestation, string $email): void
    {
        $chemin = $this->ecrireFichierTemporaire($attestation);

        try {
            $this->envoyer($attestation, $email, $chemin);
        } finally {
            @unlink($chemin);
        }
    }

    /** Contenu binaire du PDF, régénéré à la demande depuis les valeurs figées. */
    public function telecharger(AttestationPaiement $attestation): string
    {
        return $this->pdf->rendu($attestation);
    }

    /** Nom de fichier proposé au téléchargement et en pièce jointe. */
    public function nomFichier(AttestationPaiement $attestation): string
    {
        return sprintf(
            'attestation_paiement_%s_%s.pdf',
            $attestation->getLicencieNom(),
            $attestation->getLicenciePrenom(),
        );
    }

    /** @return \App\Entity\Transaction[] */
    public function transactionsDe(Licencie $licencie): array
    {
        return $this->transactionRepository->findAllByLicencieAndSeason($licencie, $licencie->getSeason());
    }

    public function resteAPayer(Licencie $licencie): float
    {
        $du = (float) $this->cotisationResolver->resolve($licencie);
        $paye = $this->transactionRepository->sumByLicencieAndSeason($licencie, $licencie->getSeason());

        return $du - $paye;
    }

    private function envoyer(AttestationPaiement $attestation, string $email, string $cheminPdf): void
    {
        $this->mailer->sendAttestationPaiement($attestation, $email, $cheminPdf, $this->nomFichier($attestation));

        $attestation->setEnvoyeeLe(new \DateTimeImmutable());
        $attestation->setEnvoyeeA($email);
        $this->em->flush();
    }

    private function ecrireFichierTemporaire(AttestationPaiement $attestation): string
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'attestation_');
        file_put_contents($chemin, $this->pdf->rendu($attestation));

        return $chemin;
    }

    private function utilisateurCourant(): ?\App\Entity\User
    {
        $user = $this->security->getUser();

        return $user instanceof \App\Entity\User ? $user : null;
    }
}
