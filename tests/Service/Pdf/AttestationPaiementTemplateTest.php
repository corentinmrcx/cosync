<?php declare(strict_types=1);

namespace App\Tests\Service\Pdf;

use App\Entity\AttestationPaiement;
use App\Entity\ClubSettings;
use App\Entity\Season;
use App\Enum\Civilite;
use App\Enum\LienParente;
use App\Enum\PaymentMode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Ce que le document dit, mot pour mot. Une attestation part chez un employeur : les
 * formulations qui l'engagent — l'accord du « soussigné », le lien de parenté, la mention
 * légale — ne doivent pas se dégrader au fil des retouches du gabarit.
 */
final class AttestationPaiementTemplateTest extends KernelTestCase
{
    private function render(AttestationPaiement $attestation, ?string $signatureDataUrl = null, bool $apercu = false): string
    {
        self::bootKernel();

        // Les globales Twig du projet lisent la saison courante en session.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);

        return self::getContainer()->get(Environment::class)->render('pdf/attestation_paiement.html.twig', [
            'attestation' => $attestation,
            'club' => $this->club(),
            'signatureDataUrl' => $signatureDataUrl,
            'logoDataUrl' => 'data:image/png;base64,iVBORw0KGgo=',
            'foyerLogoDataUrl' => null,
            'previewMode' => $apercu,
        ]);
    }

    public function testLaFormuleSAccordeAuFemininQuandLaSignataireEstUneFemme(): void
    {
        $html = $this->render($this->attestation());

        self::assertStringContainsString('Je soussignée', $html);
        self::assertStringContainsString('Mme Claudine Moreaux', $html);
        self::assertStringContainsString('trésorière', $html);
    }

    public function testLaFormuleSAccordeAuMasculin(): void
    {
        $attestation = $this->attestation();
        $attestation->setSignataireCivilite(Civilite::M)
            ->setSignataireNom('Bernard Dupuis')
            ->setSignataireQualite('président');

        $html = $this->render($attestation);

        self::assertStringContainsString('Je soussigné,', $html);
        self::assertStringNotContainsString('Je soussignée', $html);
    }

    public function testLeMontantEstDonneEnChiffresEtEnLettres(): void
    {
        $html = $this->render($this->attestation());

        self::assertStringContainsString('120,00 €', $html);
        self::assertStringContainsString('cent vingt euros', $html);
    }

    public function testLeLienDeParenteNommeLeLicencie(): void
    {
        $html = $this->render($this->attestation());

        self::assertStringContainsString('concernant son fils', $html);
        self::assertStringContainsString('Thomas MARCOUX', $html);
    }

    /** Un adulte atteste de sa propre licence : la clause « concernant … » n'a plus de sens. */
    public function testUnAdulteNAPasDeClauseDeParente(): void
    {
        $attestation = $this->attestation();
        $attestation->setLienParente(LienParente::LUI_MEME);

        $html = $this->render($attestation);

        self::assertStringNotContainsString('concernant', $html);
    }

    public function testLaMentionLegaleEtLeLieuFigurentAuPied(): void
    {
        $html = $this->render($this->attestation());

        self::assertStringContainsString('pour servir et valoir ce que de droit', $html);
        self::assertStringContainsString('Fait à Soudron', $html);
    }

    public function testLIdentiteDeLAssociationFigureEnTete(): void
    {
        // Décodé : Twig échappe l'apostrophe de « l'Église » en &#039;.
        $html = html_entity_decode($this->render($this->attestation()));

        self::assertStringContainsString('Foyer de Soudron', $html);
        self::assertStringContainsString('1 Rue de l\'Église', $html);
        self::assertStringContainsString('51320 Soudron', $html);
        self::assertStringContainsString('SIRET 488 728 794 00010', $html);
    }

    /**
     * Sans paraphe numérisé, le document reste émissible : c'est ce qui permet au club de
     * s'en servir avant d'avoir scanné quoi que ce soit.
     */
    public function testSansSignatureNumeriseeUnCadreAsignerEstImprime(): void
    {
        $html = $this->render($this->attestation(), signatureDataUrl: null);

        self::assertStringContainsString('class="cadre"', $html);
        self::assertStringNotContainsString('class="paraphe"', $html);
    }

    public function testAvecSignatureNumeriseeLeCadreDisparait(): void
    {
        $html = $this->render($this->attestation(), signatureDataUrl: 'data:image/png;base64,iVBORw0KGgo=');

        self::assertStringContainsString('class="paraphe"', $html);
        self::assertStringNotContainsString('class="cadre"', $html);
    }

    /** Un aperçu ne doit jamais pouvoir passer pour un document officiel. */
    public function testLApercuPorteSonBandeau(): void
    {
        self::assertStringContainsString('APERÇU — NON OFFICIEL', $this->render($this->attestation(), apercu: true));
        self::assertStringNotContainsString('APERÇU — NON OFFICIEL', $this->render($this->attestation()));
    }

    public function testUnPaiementFractionneNommeSesModesAuPluriel(): void
    {
        $attestation = $this->attestation();
        $attestation->setModes([PaymentMode::CHEQUE, PaymentMode::ESPECES]);

        $html = $this->render($attestation);

        self::assertStringContainsString('Modes de paiement', $html);
        self::assertStringContainsString('Chèque, Espèces', $html);
    }

    private function club(): ClubSettings
    {
        return (new ClubSettings())
            ->setAssociationNom('Foyer de Soudron')
            ->setAssociationAdresse('1 Rue de l\'Église')
            ->setAssociationCodePostal('51320')
            ->setAssociationVille('Soudron')
            ->setAssociationSiret('488 728 794 00010')
            ->setAssociationEmail('foyerdesoudron@gmail.com');
    }

    private function attestation(): AttestationPaiement
    {
        return (new AttestationPaiement())
            ->setSeason((new Season())->setLabel('2026-2027'))
            ->setLicencieNom('MARCOUX')
            ->setLicenciePrenom('Thomas')
            ->setDestinataireCivilite(Civilite::MME)
            ->setDestinatairePrenom('Ericka')
            ->setDestinataireNom('Marcoux')
            ->setLienParente(LienParente::SON_FILS)
            ->setMontant('120.00')
            ->setMontantEnLettres('cent vingt euros')
            ->setDatePaiement(new \DateTimeImmutable('2026-08-26'))
            ->setModes([PaymentMode::CB_ONLINE])
            ->setSignataireCivilite(Civilite::MME)
            ->setSignataireNom('Claudine Moreaux')
            ->setSignataireQualite('trésorière');
    }
}
