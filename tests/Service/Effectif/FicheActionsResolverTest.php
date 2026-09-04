<?php declare(strict_types=1);

namespace App\Tests\Service\Effectif;

use App\Entity\Dirigeant;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Enum\DirigeantStatut;
use App\Enum\FicheAction;
use App\Enum\LicenceStatus;
use App\Service\Effectif\FicheActionsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Quelle action la fiche met-elle en avant ?
 *
 * L'en-tête alignait jusqu'à cinq boutons, dont trois en « bouton principal » : plus rien ne
 * disait ce que l'écran attendait. La règle — une seule action mise en avant, la première
 * étape non franchie du parcours — vit dans ce résolveur, ces tests la tiennent, pour les
 * deux populations : le tri est le même, seuls les parcours diffèrent.
 */
final class FicheActionsResolverTest extends TestCase
{
    private FicheActionsResolver $resolver;

    protected function setUp(): void
    {
        // Compte tout-puissant : ces tests portent sur l'ordre du parcours, pas sur les droits
        // — ceux-là sont tenus par PermissionsAccesTest et par le contrôle CI des contrôleurs.
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);

        $this->resolver = new FicheActionsResolver($security);
    }

    public function testUnDossierImporteMetEnAvantLEnvoiDuLien(): void
    {
        $actions = $this->resolver->pourLicencie(
            $this->licencie(LicenceStatus::IMPORTED),
            autorisationsManquantes: false,
            signatureManquante: false,
            attestationPossible: false,
        );

        self::assertSame(FicheAction::ENVOYER_LIEN, $actions->principale);
        self::assertSame([FicheAction::MODIFIER], $actions->secondaires);
    }

    public function testUnDossierSoldeMetEnAvantLaValidationFootclubs(): void
    {
        $actions = $this->resolver->pourLicencie(
            $this->licencie(LicenceStatus::A_VALIDER_FFF),
            autorisationsManquantes: false,
            signatureManquante: false,
            attestationPossible: true,
        );

        self::assertSame(FicheAction::VALIDER_FFF, $actions->principale);
        self::assertSame([FicheAction::MODIFIER, FicheAction::ATTESTATION_PAIEMENT], $actions->secondaires);
    }

    /**
     * Ce qui part par mail passe avant : une relance attend une réponse, la validation
     * FootClubs n'attend que le club et peut se faire n'importe quand.
     */
    public function testUneRelancePasseAvantLaValidationFootclubs(): void
    {
        $actions = $this->resolver->pourLicencie(
            $this->licencie(LicenceStatus::A_VALIDER_FFF),
            autorisationsManquantes: false,
            signatureManquante: true,
            attestationPossible: false,
        );

        self::assertSame(FicheAction::DEMANDER_SIGNATURE, $actions->principale);
        self::assertContains(FicheAction::VALIDER_FFF, $actions->secondaires, 'La validation reste accessible, en second rang.');
    }

    /** Défaire une validation ne se propose jamais en premier plan. */
    public function testAnnulerLaValidationResteDansLeMenu(): void
    {
        $actions = $this->resolver->pourLicencie(
            $this->licencie(LicenceStatus::VALIDATED),
            autorisationsManquantes: false,
            signatureManquante: false,
            attestationPossible: false,
        );

        self::assertNull($actions->principale);
        self::assertSame([FicheAction::MODIFIER, FicheAction::ANNULER_VALIDATION_FFF], $actions->secondaires);
    }

    /**
     * Sans adresse, aucune relance n'est jouable : ni en avant, ni dans le menu. Le motif est
     * rendu — « rien ne s'affiche » n'apprend rien à l'admin qui cherche le bouton.
     */
    public function testSansEmailLeMotifRemplaceLaction(): void
    {
        $actions = $this->resolver->pourLicencie(
            $this->licencie(LicenceStatus::LINK_SENT, email: null),
            autorisationsManquantes: true,
            signatureManquante: false,
            attestationPossible: false,
        );

        self::assertNull($actions->principale);
        self::assertSame('Pas d\'email renseigné', $actions->blocage);
        self::assertSame([FicheAction::MODIFIER], $actions->secondaires);
    }

    /** Une fiche sans dossier club — créée à la main, rien de rempli — attend son lien. */
    public function testUneFicheSansDossierAttendSonLien(): void
    {
        $licencie = (new Licencie())->setEmail('kevin@example.test');

        $actions = $this->resolver->pourLicencie(
            $licencie,
            autorisationsManquantes: false,
            signatureManquante: false,
            attestationPossible: false,
        );

        self::assertSame(FicheAction::ENVOYER_LIEN, $actions->principale);
    }

    // — Dirigeants : même tri, parcours plus court —

    public function testUnDirigeantJamaisContacteMetEnAvantLEnvoiDuLien(): void
    {
        $actions = $this->resolver->pourDirigeant($this->dirigeant(), DirigeantStatut::LIEN_NON_ENVOYE);

        self::assertSame(FicheAction::ENVOYER_LIEN, $actions->principale);
        self::assertSame([FicheAction::MODIFIER], $actions->secondaires);
    }

    /**
     * Un document ajouté en cours de saison se fait signer en renvoyant le même lien : le
     * formulaire public ne redemande que les étapes manquantes.
     */
    public function testUnDocumentASignerRemetLeLienEnAvant(): void
    {
        $actions = $this->resolver->pourDirigeant($this->dirigeant(), DirigeantStatut::DOCUMENT_A_SIGNER);

        self::assertSame(FicheAction::ENVOYER_LIEN, $actions->principale);
    }

    public function testUnDossierDirigeantCompletMetEnAvantLaValidationFootclubs(): void
    {
        $actions = $this->resolver->pourDirigeant($this->dirigeant(), DirigeantStatut::A_VALIDER_FFF);

        self::assertSame(FicheAction::VALIDER_FFF, $actions->principale);
        self::assertSame([FicheAction::MODIFIER], $actions->secondaires);
    }

    /**
     * Une licence administrative n'attend ni lien ni document — mais elle existe bien à la
     * FFF, elle se valide donc comme les autres. Surtout, aucun envoi de lien ne se propose :
     * `DirigeantLinkService::send()` le refuserait.
     */
    public function testUneLicenceAdministrativeSeValideSansJamaisProposerLeLien(): void
    {
        $actions = $this->resolver->pourDirigeant($this->dirigeant(), DirigeantStatut::LICENCE_ADMINISTRATIVE);

        self::assertSame(FicheAction::VALIDER_FFF, $actions->principale);
        self::assertNotContains(FicheAction::ENVOYER_LIEN, $actions->secondaires);
    }

    public function testAnnulerLaValidationDunDirigeantResteDansLeMenu(): void
    {
        $actions = $this->resolver->pourDirigeant($this->dirigeant(), DirigeantStatut::VALIDE);

        self::assertNull($actions->principale);
        self::assertSame([FicheAction::MODIFIER, FicheAction::ANNULER_VALIDATION_FFF], $actions->secondaires);
    }

    public function testSansEmailLeMotifRemplaceLactionDirigeant(): void
    {
        $actions = $this->resolver->pourDirigeant($this->dirigeant(email: null), DirigeantStatut::LIEN_ENVOYE);

        self::assertNull($actions->principale);
        self::assertSame('Pas d\'email renseigné', $actions->blocage);
        self::assertSame([FicheAction::MODIFIER], $actions->secondaires);
    }

    private function licencie(LicenceStatus $statut, ?string $email = 'kevin@example.test'): Licencie
    {
        $licencie = (new Licencie())->setEmail($email);
        $licencie->setDossierClub((new DossierClub())->setLicencie($licencie)->setStatus($statut));

        return $licencie;
    }

    private function dirigeant(?string $email = 'josiane@example.test'): Dirigeant
    {
        return (new Dirigeant())->setEmail($email);
    }
}
