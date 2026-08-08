<?php declare(strict_types=1);

namespace App\Tests\Service\Document;

use App\Entity\Dirigeant;
use App\Entity\DocumentSignable;
use App\Entity\Season;
use App\Enum\DirigeantRole;
use App\Service\Document\DocumentRequirementResolver;
use App\Tests\Support\DocumentFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Ciblage des documents : qui doit signer quoi.
 *
 * Le référentiel des rôles étant fermé à trois valeurs, un document qui ne
 * correspond à aucun rôle (la charte communication) se cible nommément. Les deux
 * mécanismes s'additionnent : « les responsables d'équipe, plus Marie ».
 */
final class DocumentRequirementResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentFixtures $fixtures;
    private DocumentRequirementResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em       = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new DocumentFixtures($this->em);
        $this->resolver = self::getContainer()->get(DocumentRequirementResolver::class);
    }

    public function testUnDocumentSansCiblageEstDemandeATousLesDirigeants(): void
    {
        $season      = $this->season();
        $responsable = $this->dirigeant($season, 'DUPONT', DirigeantRole::RESPONSABLE_FOOT);
        $benevole    = $this->dirigeant($season, 'MARTIN', DirigeantRole::DIRIGEANT);

        $this->fixtures->documentDirigeant($season);
        $this->em->flush();

        self::assertCount(1, $this->resolver->manquantsPourDirigeant($responsable));
        self::assertCount(1, $this->resolver->manquantsPourDirigeant($benevole));
    }

    public function testUnDocumentCibleParRoleNeVaQuAuxPorteursDeCeRole(): void
    {
        $season      = $this->season();
        $responsable = $this->dirigeant($season, 'DUPONT', DirigeantRole::RESPONSABLE_EQUIPE);
        $benevole    = $this->dirigeant($season, 'MARTIN', DirigeantRole::DIRIGEANT);

        $this->fixtures->documentDirigeant($season, roles: [DirigeantRole::RESPONSABLE_EQUIPE]);
        $this->em->flush();

        self::assertCount(1, $this->resolver->manquantsPourDirigeant($responsable));
        self::assertSame([], $this->resolver->manquantsPourDirigeant($benevole));
    }

    public function testUnDocumentCibleNommementNeVaQuALaPersonneDesignee(): void
    {
        $season = $this->season();
        $marie  = $this->dirigeant($season, 'DUPONT', DirigeantRole::DIRIGEANT);
        $kevin  = $this->dirigeant($season, 'MARTIN', DirigeantRole::DIRIGEANT);

        $this->fixtures->documentDirigeant($season, code: 'charte_communication', dirigeants: [$marie]);
        $this->em->flush();

        self::assertCount(1, $this->resolver->manquantsPourDirigeant($marie));
        self::assertSame([], $this->resolver->manquantsPourDirigeant($kevin));
    }

    public function testRoleEtDesignationSAdditionnent(): void
    {
        $season      = $this->season();
        $responsable = $this->dirigeant($season, 'DUPONT', DirigeantRole::RESPONSABLE_EQUIPE);
        $marie       = $this->dirigeant($season, 'LAGRANGE', DirigeantRole::DIRIGEANT);
        $kevin       = $this->dirigeant($season, 'MARTIN', DirigeantRole::DIRIGEANT);

        $this->fixtures->documentDirigeant(
            $season,
            roles: [DirigeantRole::RESPONSABLE_EQUIPE],
            dirigeants: [$marie],
        );
        $this->em->flush();

        self::assertCount(1, $this->resolver->manquantsPourDirigeant($responsable));
        self::assertCount(1, $this->resolver->manquantsPourDirigeant($marie));
        self::assertSame([], $this->resolver->manquantsPourDirigeant($kevin));
    }

    public function testUnDocumentInactifNEstDemandeAPersonne(): void
    {
        $season    = $this->season();
        $dirigeant = $this->dirigeant($season, 'MARTIN', DirigeantRole::DIRIGEANT);

        $this->fixtures->documentDirigeant($season, actif: false);
        $this->em->flush();

        self::assertSame([], $this->resolver->manquantsPourDirigeant($dirigeant));
    }

    public function testUnDocumentDUneAutreSaisonNEstPasDemande(): void
    {
        $saisonCourante   = $this->season('2025-2026');
        $saisonPrecedente = $this->season('2024-2025');
        $dirigeant        = $this->dirigeant($saisonCourante, 'MARTIN', DirigeantRole::DIRIGEANT);

        $this->fixtures->documentDirigeant($saisonPrecedente);
        $this->em->flush();

        self::assertSame([], $this->resolver->manquantsPourDirigeant($dirigeant));
    }

    public function testUnDocumentSigneDisparaitDesManquantsMaisResteAttendu(): void
    {
        $season    = $this->season();
        $dirigeant = $this->dirigeant($season, 'MARTIN', DirigeantRole::DIRIGEANT);

        $document = $this->fixtures->documentDirigeant($season);
        $this->em->flush();

        self::assertCount(1, $this->resolver->manquantsPourDirigeant($dirigeant));

        $this->fixtures->signerParDirigeant($document, $dirigeant);
        $this->em->flush();

        self::assertSame([], $this->resolver->manquantsPourDirigeant($dirigeant));
        self::assertCount(1, $this->resolver->attendusPourDirigeant($dirigeant), 'La fiche admin doit toujours l\'afficher.');
    }

    public function testLesDirigeantsEnAttenteExcluentCeuxQuiOntSigne(): void
    {
        $season = $this->season();
        $marie  = $this->dirigeant($season, 'DUPONT', DirigeantRole::DIRIGEANT);
        $kevin  = $this->dirigeant($season, 'MARTIN', DirigeantRole::DIRIGEANT);

        $document = $this->fixtures->documentDirigeant($season);
        $this->em->flush();

        self::assertCount(2, $this->resolver->dirigeantsEnAttente($document));

        $this->fixtures->signerParDirigeant($document, $marie);
        $this->em->flush();

        $enAttente = $this->resolver->dirigeantsEnAttente($document);

        self::assertCount(1, $enAttente);
        self::assertSame((string) $kevin->getUuid(), (string) $enAttente[0]->getUuid());
    }

    public function testUnDocumentLicencieNEstPasDemandeAuxDirigeants(): void
    {
        $season    = $this->season();
        $dirigeant = $this->dirigeant($season, 'MARTIN', DirigeantRole::DIRIGEANT);

        $this->fixtures->documentLicencie($season);
        $this->em->flush();

        self::assertSame([], $this->resolver->manquantsPourDirigeant($dirigeant));
    }

    private function dirigeant(Season $season, string $nom, DirigeantRole $role): Dirigeant
    {
        $dirigeant = (new Dirigeant())
            ->setNom($nom)
            ->setPrenom('Kevin')
            ->setSeason($season)
            ->setRole($role);

        $this->em->persist($dirigeant);
        $this->em->flush();

        return $dirigeant;
    }

    private function season(string $label = '2025-2026'): Season
    {
        $season = (new Season())->setLabel($label)->setCotisationDefaut(85);

        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }
}
