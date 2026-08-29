<?php declare(strict_types=1);

namespace App\Tests\Service\Dirigeant;

use App\Entity\Dirigeant;
use App\Entity\DocumentSignable;
use App\Entity\Season;
use App\Enum\DirigeantStatut;
use App\Service\Dirigeant\DirigeantStatutResolver;
use App\Tests\Support\DocumentFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Statut affiché d'un dirigeant — l'ordre des règles, qui est toute la logique.
 *
 * La liste des dirigeants n'avait aucune colonne d'avancement : il fallait ouvrir chaque
 * fiche pour savoir si le formulaire était rempli. Le statut est calculé et non stocké,
 * ces tests sont donc le seul garde-fou de la règle.
 */
final class DirigeantStatutResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentFixtures $fixtures;
    private DirigeantStatutResolver $resolver;
    private Season $season;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new DocumentFixtures($this->em);
        $this->resolver = self::getContainer()->get(DirigeantStatutResolver::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $this->em->persist($this->season);
        $this->em->flush();
    }

    public function testLienJamaisEnvoye(): void
    {
        $dirigeant = $this->persister($this->dirigeant());

        self::assertSame(DirigeantStatut::LIEN_NON_ENVOYE, $this->resolver->pour($dirigeant));
    }

    public function testLienEnvoyeMaisFormulaireNonRempli(): void
    {
        $dirigeant = $this->persister($this->dirigeant()->setLinkSentAt(new \DateTimeImmutable()));

        self::assertSame(DirigeantStatut::LIEN_ENVOYE, $this->resolver->pour($dirigeant));
    }

    public function testDossierCompletResteAValiderSurFootclubs(): void
    {
        $dirigeant = $this->persister($this->dirigeantRenseigne());
        $this->signerLeDocument($dirigeant, $this->fixtures->documentDirigeant($this->season));

        self::assertSame(DirigeantStatut::A_VALIDER_FFF, $this->resolver->pour($dirigeant));
    }

    /**
     * Un document ajouté après coup ne renvoie pas le dirigeant à « lien envoyé » : son
     * formulaire, lui, a bien été rempli. C'est une relance de signature qui l'attend.
     */
    public function testDocumentAjouteApresCoup(): void
    {
        $dirigeant = $this->persister(
            $this->dirigeantRenseigne()->setFormCompletedAt(new \DateTimeImmutable()),
        );
        $this->fixtures->documentDirigeant($this->season);
        $this->em->flush();

        self::assertSame(DirigeantStatut::DOCUMENT_A_SIGNER, $this->resolver->pour($dirigeant));
    }

    /** Rien ne lui est demandé : ni lien, ni document. Ce n'est pas un dossier en retard. */
    public function testLicenceAdministrative(): void
    {
        $dirigeant = $this->persister($this->dirigeant()->setLicenceAdministrative(true));
        $this->fixtures->documentDirigeant($this->season);
        $this->em->flush();

        self::assertSame(DirigeantStatut::LICENCE_ADMINISTRATIVE, $this->resolver->pour($dirigeant));
    }

    /**
     * « Validé » passe avant tout le reste, licence administrative comprise : celle-ci
     * n'attend aucun document mais existe bien à la FFF, et le club la signe comme les
     * autres. Sans cet ordre, une licence validée s'afficherait comme si rien n'avait été fait.
     */
    public function testValideLEmporteSurLaLicenceAdministrative(): void
    {
        $dirigeant = $this->persister(
            $this->dirigeant()
                ->setLicenceAdministrative(true)
                ->setValidatedFffAt(new \DateTimeImmutable()),
        );

        self::assertSame(DirigeantStatut::VALIDE, $this->resolver->pour($dirigeant));
    }

    /** Le lot doit rendre exactement ce que rend la fiche, sinon la liste ment. */
    public function testLeLotDonneLesMemesStatutsQueLUnite(): void
    {
        $jamaisContacte = $this->persister($this->dirigeant());
        $complet = $this->persister($this->dirigeantRenseigne(nom: 'DURAND'));
        $this->signerLeDocument($complet, $this->fixtures->documentDirigeant($this->season));

        $statuts = $this->resolver->pourLot($this->season, [$jamaisContacte, $complet]);

        self::assertSame(DirigeantStatut::LIEN_NON_ENVOYE, $statuts[(string) $jamaisContacte->getUuid()]);
        self::assertSame(DirigeantStatut::A_VALIDER_FFF, $statuts[(string) $complet->getUuid()]);
    }

    private function dirigeant(string $nom = 'MARTIN'): Dirigeant
    {
        return (new Dirigeant())
            ->setNom($nom)
            ->setPrenom('Kevin')
            ->setSeason($this->season);
    }

    /** Toutes les informations que le formulaire public demande, sans les documents. */
    private function dirigeantRenseigne(string $nom = 'MARTIN'): Dirigeant
    {
        return $this->dirigeant($nom)
            ->setVolontaireTransport(false)
            ->setTailleHaut('L')->setTailleBas('M')->setPointure('42')
            ->setAutorisationPhoto(true);
    }

    private function persister(Dirigeant $dirigeant): Dirigeant
    {
        $this->em->persist($dirigeant);
        $this->em->flush();

        return $dirigeant;
    }

    private function signerLeDocument(Dirigeant $dirigeant, DocumentSignable $document): void
    {
        $this->fixtures->signerParDirigeant($document, $dirigeant);
        $this->em->flush();
    }
}
