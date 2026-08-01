<?php declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\Dirigeant;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\LicenceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Règles de signature du règlement intérieur des dirigeants : requis pour tous,
 * y compris les dirigeants-joueurs. Le règlement des joueurs est un autre
 * document — le signer dans son dossier licencié n'exonère de rien ici.
 */
final class DirigeantReglementTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testDirigeantNonLieDoitSignerLeReglement(): void
    {
        $dirigeant = (new Dirigeant())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setSeason($this->makeSeason())
            ->setVolontaireTransport(false)
            ->setTailleHaut('L')->setTailleBas('M')->setPointure('42')
            ->setAutorisationPhoto(true);

        self::assertTrue($dirigeant->needsReglementSignature());
        self::assertFalse($dirigeant->hasSignedReglement());
        // Tout le reste est rempli mais le règlement manque → dossier incomplet.
        self::assertFalse($dirigeant->isPublicFormComplete());

        $dirigeant->setReglementSignePath('/tmp/ri.pdf');
        self::assertTrue($dirigeant->hasSignedReglement());
        self::assertTrue($dirigeant->isPublicFormComplete());
    }

    public function testDirigeantJoueurDoitSignerMemeSiSonLicencieASigne(): void
    {
        $dirigeant = $this->makeDirigeantLieAuLicencie(licencieSigne: true);

        // Le dossier joueur signé ne couvre que le règlement des joueurs.
        self::assertTrue($dirigeant->needsReglementSignature());
        self::assertFalse($dirigeant->hasSignedReglement());
        self::assertFalse($dirigeant->isPublicFormComplete());

        // Une fois le règlement dirigeants signé : taille/photo viennent du
        // licencié, transport renseigné → dossier complet.
        $dirigeant->setReglementSignePath('/tmp/ri_dirigeant.pdf');
        self::assertTrue($dirigeant->hasSignedReglement());
        self::assertTrue($dirigeant->isPublicFormComplete());
    }

    public function testDirigeantJoueurAvecLicencieNonSigneDoitSigner(): void
    {
        $dirigeant = $this->makeDirigeantLieAuLicencie(licencieSigne: false);

        self::assertTrue($dirigeant->needsReglementSignature());
        self::assertFalse($dirigeant->isPublicFormComplete());
    }

    private function makeDirigeantLieAuLicencie(bool $licencieSigne): Dirigeant
    {
        $season   = $this->makeSeason();
        $category = (new Category())->setCode('SENIOR')->setLabel('Séniors')->setIsEcoleFoot(false);

        $licencie = (new Licencie())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('1990-01-01'))
            ->setCategory($category)
            ->setSeason($season);

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus(LicenceStatus::FORM_COMPLETED)
            ->setIsSigned($licencieSigne);

        $dirigeant = (new Dirigeant())
            ->setNom('MARTIN')
            ->setPrenom('Kevin')
            ->setSeason($season)
            ->setLicencie($licencie)
            ->setVolontaireTransport(false);

        $this->em->persist($season);
        $this->em->persist($category);
        $this->em->persist($licencie);
        $this->em->persist($dossier);
        $this->em->persist($dirigeant);
        $this->em->flush();

        // Recharge pour hydrater la relation inverse Licencie->DossierClub.
        $uuid = $dirigeant->getUuid();
        $this->em->clear();

        return $this->em->find(Dirigeant::class, $uuid);
    }

    private function makeSeason(): Season
    {
        return (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
    }
}
