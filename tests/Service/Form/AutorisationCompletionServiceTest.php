<?php declare(strict_types=1);

namespace App\Tests\Service\Form;

use App\DTO\AutorisationCompletionData;
use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\AutorisationManquante;
use App\Enum\LicenceStatus;
use App\Service\Form\AutorisationCompletionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Complétion a posteriori des autorisations : conteneur réel + base réelle
 * (transaction annulée par dama/doctrine-test-bundle).
 */
final class AutorisationCompletionServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AutorisationCompletionService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(AutorisationCompletionService::class);
    }

    public function testAutorisationsManquantesVideSiFormulaireNonComplete(): void
    {
        $licencie = $this->makeLicencie('U11', formCompleted: false);

        self::assertSame([], AutorisationManquante::valeurs($this->service->manquantes($licencie)));
        self::assertFalse($this->service->hasMissing($licencie));
    }

    public function testAutorisationsManquantesSeniorNeContientQuePhoto(): void
    {
        // Un sénior n'a pas d'autorisations de transport applicables.
        $licencie = $this->makeLicencie('SENIOR', formCompleted: true);

        self::assertSame(['photo'], AutorisationManquante::valeurs($this->service->manquantes($licencie)));
        self::assertTrue($this->service->hasMissing($licencie));
    }

    public function testAutorisationsManquantesJeuneListeToutesLesAutorisationsNulles(): void
    {
        $licencie = $this->makeLicencie('U11', formCompleted: true);

        self::assertSame(
            ['photo', 'accident', 'transport_dirigeants', 'transport_parents', 'volontaire'],
            AutorisationManquante::valeurs($this->service->manquantes($licencie)),
        );
    }

    public function testAutorisationsManquantesVideQuandToutEstRenseigne(): void
    {
        $licencie = $this->makeLicencie('U11', formCompleted: true, fill: true);

        self::assertSame([], AutorisationManquante::valeurs($this->service->manquantes($licencie)));
        self::assertFalse($this->service->hasMissing($licencie));
    }

    public function testApplyRenseigneLeChampFourniEtConsommeLeLien(): void
    {
        $licencie = $this->makeLicencie('SENIOR', formCompleted: true);
        self::assertTrue($licencie->isFormTokenValid());

        $this->service->apply(
            $licencie,
            new AutorisationCompletionData(
                autorisationPhoto: true,
                autorisationAccident: null,
                autorisationTransportDirigeants: null,
                autorisationTransportParents: null,
                volontaireTransport: null,
            ),
        );

        $reloaded = $this->refetch($licencie);
        self::assertTrue($reloaded->getDossierClub()->getAutorisationPhoto());
        self::assertFalse($reloaded->isFormTokenValid(), 'Le lien de complétion doit être à usage unique.');
        self::assertSame([], AutorisationManquante::valeurs($this->service->manquantes($reloaded)));
    }

    public function testApplyNeTouchePasAuxAutorisationsNonFournies(): void
    {
        $licencie = $this->makeLicencie('U11', formCompleted: true);

        // On ne fournit que « volontaire = non » : les autres restent à compléter.
        $this->service->apply(
            $licencie,
            new AutorisationCompletionData(
                autorisationPhoto: null,
                autorisationAccident: null,
                autorisationTransportDirigeants: null,
                autorisationTransportParents: null,
                volontaireTransport: false,
            ),
        );

        $reloaded = $this->refetch($licencie);
        self::assertFalse($reloaded->getDossierClub()->getVolontaireTransport());
        self::assertNull($reloaded->getDossierClub()->getAutorisationPhoto());
        self::assertSame(
            ['photo', 'accident', 'transport_dirigeants', 'transport_parents'],
            AutorisationManquante::valeurs($this->service->manquantes($reloaded)),
        );
    }

    /** Recharge le licencié depuis la base (hydrate le DossierClub côté inverse). */
    private function refetch(Licencie $licencie): Licencie
    {
        $uuid = $licencie->getUuid();
        $this->em->flush();
        $this->em->clear();

        return $this->em->find(Licencie::class, $uuid);
    }

    private function makeLicencie(string $code, bool $formCompleted, bool $fill = false): Licencie
    {
        $season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $category = (new Category())
            ->setCode($code)
            ->setLabel($code)
            ->setIsEcoleFoot(str_starts_with($code, 'U'));

        $licencie = (new Licencie())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('2014-01-01'))
            ->setCategory($category)
            ->setSeason($season)
            ->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));

        $dossier = (new DossierClub())
            ->setLicencie($licencie)
            ->setStatus(LicenceStatus::FORM_COMPLETED);

        if ($formCompleted) {
            $dossier->setFormCompletedAt(new \DateTimeImmutable());
        }
        if ($fill) {
            $dossier->setAutorisationPhoto(true)
                ->setAutorisationAccident(true)
                ->setAutorisationTransportDirigeants(true)
                ->setAutorisationTransportParents(false)
                ->setVolontaireTransport(false);
        }

        $this->em->persist($season);
        $this->em->persist($category);
        $this->em->persist($licencie);
        $this->em->persist($dossier);
        $this->em->flush();

        // Recharge pour hydrater la relation inverse Licencie->DossierClub.
        $uuid = $licencie->getUuid();
        $this->em->clear();

        return $this->em->find(Licencie::class, $uuid);
    }
}
