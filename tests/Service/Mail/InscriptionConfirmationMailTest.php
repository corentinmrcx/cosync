<?php declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use App\Enum\PaymentMode;
use App\Service\Mail\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mime\Email;

/**
 * Accusé de réception envoyé après la soumission du formulaire.
 *
 * C'est la seule trace écrite dont dispose le licencié tant qu'il n'a pas payé — le mail
 * de validation n'arrive qu'après encaissement, donc jamais pour qui ne règle pas. Son
 * contenu doit donc porter tout ce dont le licencié a besoin pour aller au bout, et
 * en particulier le RIB et le libellé exact pour un virement.
 */
final class InscriptionConfirmationMailTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private const IBAN = 'FR76 3000 4000 0300 0000 0000 143';
    private const BIC = 'BNPAFRPPXXX';

    /** @var string[] */
    private array $fichiersTemporaires = [];

    protected function tearDown(): void
    {
        foreach ($this->fichiersTemporaires as $path) {
            @unlink($path);
        }
        $this->fichiersTemporaires = [];

        parent::tearDown();
    }

    /* ── Contenu selon le mode de paiement ── */

    public function testLeVirementApporteLIbanDeLaSaisonEtLeLibelleExact(): void
    {
        $licencie = $this->seed([PaymentMode::VIREMENT]);

        $this->envoyer($licencie);
        $corps = $this->corps();

        self::assertStringContainsString(self::IBAN, $corps, 'L\'IBAN doit venir de la saison, pas d\'une constante');
        self::assertStringContainsString(self::BIC, $corps);
        self::assertStringContainsString('Association Test', $corps, 'Le titulaire du compte doit figurer');
        self::assertStringContainsString(
            'COTISATION MARTIN Kevin 2025-2026',
            $corps,
            'Sans libellé exact, le virement est impossible à rapprocher du licencié',
        );
    }

    public function testUnPaiementAuLocalNExposePasLeRibEtDonneLOrdreDuCheque(): void
    {
        $licencie = $this->seed([PaymentMode::CHEQUE]);

        $this->envoyer($licencie);
        $corps = $this->corps();

        self::assertStringNotContainsString(self::IBAN, $corps);
        self::assertStringContainsString('remettre à un dirigeant', $corps);
        self::assertStringContainsString('à l\'ordre de', $corps);
        self::assertStringContainsString('Association Test', $corps);
    }

    public function testLesEspecesNAnnoncentPasDOrdreDeCheque(): void
    {
        $this->envoyer($this->seed([PaymentMode::ESPECES]));

        self::assertStringNotContainsString('à l\'ordre de', $this->corps());
    }

    /**
     * Le CTA renvoie vers la page de confirmation, jamais vers checkout_start : cette route
     * GET crée une intention de paiement chez HelloAsso, qu'un client mail préchargeant les
     * liens déclencherait à vide.
     */
    public function testLePaiementParCarteRenvoieVersLaConfirmationPasVersHelloAsso(): void
    {
        $licencie = $this->seed([PaymentMode::CB_ONLINE]);

        $this->envoyer($licencie);
        $corps = $this->corps();

        self::assertStringContainsString('/inscription/' . $licencie->getUuid() . '/confirmation', $corps);
        self::assertStringNotContainsString('paiement/demarrer', $corps);
        self::assertStringNotContainsString('checkout', $corps);
    }

    public function testLeMultiPaiementCumuleLesInstructions(): void
    {
        $this->envoyer($this->seed([PaymentMode::VIREMENT, PaymentMode::ESPECES]));
        $corps = $this->corps();

        self::assertStringContainsString(self::IBAN, $corps);
        self::assertStringContainsString('remettre à un dirigeant', $corps);
    }

    /** Tous les cas : le montant dû et la règle « validée qu'à réception du paiement ». */
    public function testLeMailPorteToujoursLeMontantEtLaConditionDeValidation(): void
    {
        // Une saison par cas : le label porte une contrainte d'unicité.
        foreach ([PaymentMode::VIREMENT, PaymentMode::CHEQUE, PaymentMode::CB_ONLINE] as $i => $mode) {
            $this->envoyer($this->seed([$mode], label: sprintf('202%d-202%d', $i, $i + 1)));
            $corps = $this->corps();

            self::assertStringContainsString('85 €', $corps, sprintf('Montant absent pour %s', $mode->value));
            self::assertStringContainsString(
                'ne sera définitivement validée qu\'à réception du paiement',
                $corps,
                sprintf('Condition de validation absente pour %s', $mode->value),
            );
        }
    }

    /** Une saison sans IBAN ne doit pas empêcher l'envoi — elle masque juste le bloc. */
    public function testUneSaisonSansIbanEnvoieQuandMemeLeMail(): void
    {
        $licencie = $this->seed([PaymentMode::VIREMENT], avecIban: false);

        $this->envoyer($licencie);

        self::assertCount(1, self::getMailerMessages());
        self::assertStringNotContainsString('IBAN', $this->corps());
    }

    public function testLeSujetEtLeDestinataireSontCorrects(): void
    {
        $this->envoyer($this->seed([PaymentMode::CHEQUE]));

        $message = self::getMailerMessages()[0];
        self::assertInstanceOf(Email::class, $message);
        self::assertSame('kevin.martin@example.test', $message->getTo()[0]->getAddress());
        self::assertStringContainsString('Inscription bien reçue', $message->getSubject());
    }

    public function testUnLicencieSansEmailNeDeclencheAucunEnvoi(): void
    {
        $licencie = $this->seed([PaymentMode::CHEQUE], email: null);

        $this->envoyer($licencie);

        self::assertCount(0, self::getMailerMessages());
    }

    /* ── Pièces jointes ── */

    public function testLesDocumentsSignesSontJoints(): void
    {
        $licencie = $this->seed([PaymentMode::CHEQUE]);

        $this->envoyer($licencie, [
            $this->pdfTemporaire('reglement') => 'Règlement intérieur.pdf',
            $this->pdfTemporaire('charte') => 'Charte du joueur.pdf',
        ]);

        $pieces = $this->piecesJointes();
        self::assertCount(2, $pieces);
        self::assertStringContainsString('%PDF', $pieces[0]->getBody());

        // Le licencié doit reconnaître ce qu'il a signé, pas lire un nom technique.
        $noms = array_map(static fn ($p): string => $p->getFilename(), $pieces);
        self::assertContains('Règlement intérieur.pdf', $noms);
        self::assertContains('Charte du joueur.pdf', $noms);
    }

    public function testUnFichierAbsentEstIgnoreSansFaireEchouerLEnvoi(): void
    {
        $licencie = $this->seed([PaymentMode::CHEQUE]);

        $this->envoyer($licencie, [
            $this->pdfTemporaire('present') => 'Règlement intérieur.pdf',
            '/var/www/html/var/pdfs/fichier_disparu.pdf' => 'Document disparu.pdf',
        ]);

        self::assertCount(1, self::getMailerMessages(), 'Le mail doit partir malgré le fichier manquant');
        self::assertCount(1, $this->piecesJointes());
    }

    /** Au-delà du plafond SMTP, mieux vaut un mail sans documents qu'un mail rejeté. */
    public function testDesPiecesJointesTropLourdesSontAbandonneesMaisLeMailPart(): void
    {
        $licencie = $this->seed([PaymentMode::VIREMENT]);
        $lourd = $this->pdfTemporaire('enorme', 9 * 1024 * 1024);

        $this->envoyer($licencie, [$lourd => 'Énorme document.pdf']);

        self::assertCount(1, self::getMailerMessages());
        self::assertCount(0, $this->piecesJointes());
        self::assertStringContainsString(self::IBAN, $this->corps(), 'Le contenu utile doit rester intact');
    }

    /* ── Outils ── */

    /** @param array<string, string> $pdfs chemin => nom affiché */
    private function envoyer(Licencie $licencie, array $pdfs = []): void
    {
        self::getContainer()->get(MailerService::class)->sendInscriptionConfirmation($licencie, 85, $pdfs);
    }

    private function corps(): string
    {
        $messages = self::getMailerMessages();
        self::assertNotEmpty($messages, 'Aucun mail envoyé');

        $dernier = end($messages);
        self::assertInstanceOf(Email::class, $dernier);

        return (string) $dernier->getHtmlBody();
    }

    /** @return \Symfony\Component\Mime\Part\DataPart[] */
    private function piecesJointes(): array
    {
        $message = self::getMailerMessages()[0];
        self::assertInstanceOf(Email::class, $message);

        return $message->getAttachments();
    }

    private function pdfTemporaire(string $nom, int $taille = 1024): string
    {
        $repertoire = self::getContainer()->getParameter('kernel.project_dir') . '/var/pdfs';
        @mkdir($repertoire, 0775, true);

        $path = sprintf('%s/test_%s_%d.pdf', $repertoire, $nom, random_int(1000, 9999));
        $contenu = "%PDF-1.4\n" . str_repeat('x', max(0, $taille - 9));

        file_put_contents($path, $contenu);
        $this->fichiersTemporaires[] = $path;

        return $path;
    }

    /** @param PaymentMode[] $intentions */
    private function seed(
        array $intentions,
        bool $avecIban = true,
        ?string $email = 'kevin.martin@example.test',
        string $label = '2025-2026',
    ): Licencie {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        static $n = 0;
        ++$n;

        $season = (new Season())->setLabel($label)->setCotisationDefaut(85);
        if ($avecIban) {
            $season->setIban(self::IBAN)->setBic(self::BIC)->setTitulaireCompte('Association Test');
        }

        $category = (new Category())->setCode('SENIOR' . $n)->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('1995-01-01'))
            ->setEmail($email)
            ->setCategory($category)
            ->setSeason($season);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus(LicenceStatus::FORM_COMPLETED)
            ->setFormCompletedAt(new \DateTimeImmutable())
            ->setPaymentIntentions($intentions);

        foreach ([$season, $category, $licencie, $dossier] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $uuid = $licencie->getUuid();
        $em->clear();

        return $em->find(Licencie::class, $uuid);
    }
}
