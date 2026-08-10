<?php declare(strict_types=1);

namespace App\Tests\Service\Cle;

use App\DTO\CleDetention;
use App\DTO\CleMouvementData;
use App\Entity\CleMouvement;
use App\Entity\Detenteur;
use App\Enum\CleMouvementType;
use App\Repository\CleMouvementRepository;
use App\Repository\DetenteurRepository;
use App\Service\Cle\CleRegistreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le registre des clés dérive la détention de l'historique : ces tests verrouillent
 * le calcul du solde, la date « détenteur depuis » et le caractère append-only.
 *
 * Ils ne parlent jamais de saison — c'est le point : le registre porte sur toute la
 * vie du club. Les attestations, elles, sont couvertes par CleRegistrePresenterTest.
 */
final class CleRegistreServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CleRegistreService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var CleMouvementRepository $mouvements */
        $mouvements = $this->em->getRepository(CleMouvement::class);
        /** @var DetenteurRepository $detenteurs */
        $detenteurs = $this->em->getRepository(Detenteur::class);

        $this->service = new CleRegistreService($mouvements, $detenteurs, $this->em);
    }

    public function testLeSoldeRetombeAZeroApresRestitutionEtPerte(): void
    {
        $detenteur = $this->makeDetenteur();

        $this->remise($detenteur, 2, '2026-01-10');
        $this->mouvement($detenteur, CleMouvementType::RESTITUTION, 1, '2026-02-01');
        $this->mouvement($detenteur, CleMouvementType::PERTE, 1, '2026-03-01');

        $detention = $this->detentionDe($detenteur);

        self::assertSame(0, $detention->solde);
        self::assertNull($detention->detenteurDepuis, 'Sans clé détenue, la date de détention est effacée.');
        self::assertSame(2, $detention->remises);
        self::assertSame(1, $detention->restitutions);
        self::assertSame(1, $detention->pertes);
        self::assertFalse($detention->estDetenteur());
    }

    public function testDetenteurDepuisEstLaDateDeLaRemiseQuiRelanceLaDetention(): void
    {
        $detenteur = $this->makeDetenteur();

        $this->remise($detenteur, 1, '2026-01-10');
        $this->mouvement($detenteur, CleMouvementType::RESTITUTION, 1, '2026-02-01');
        $this->remise($detenteur, 1, '2026-05-20');

        $detention = $this->detentionDe($detenteur);

        self::assertSame(1, $detention->solde);
        self::assertNotNull($detention->detenteurDepuis);
        self::assertSame('2026-05-20', $detention->detenteurDepuis->format('Y-m-d'));
    }

    /**
     * Le défaut que la bascule au niveau du club corrige : une clé remise en août et
     * rendue en février traverse le changement de saison. Un registre cloisonné par
     * saison affichait un solde faux des deux côtés.
     */
    public function testLeSoldeTraverseLesSaisons(): void
    {
        $detenteur = $this->makeDetenteur();

        $this->remise($detenteur, 2, '2025-08-20');
        $this->mouvement($detenteur, CleMouvementType::RESTITUTION, 1, '2026-02-10');

        $detention = $this->detentionDe($detenteur);

        self::assertSame(1, $detention->solde, 'Une clé remise en août est toujours dehors en février.');
        self::assertSame('2025-08-20', $detention->detenteurDepuis->format('Y-m-d'));
    }

    public function testUnDetenteurSansMouvementFigureAuRegistreAuSoldeZero(): void
    {
        $detenteur = $this->makeDetenteur('NOUVEAU', 'Venu');

        $detention = $this->detentionDe($detenteur);

        self::assertSame(0, $detention->solde);
        self::assertFalse($detention->estDetenteur());
        self::assertNull($detention->dernierMouvementLe);
    }

    public function testLaDetentionIndividuelleEgaleCelleCalculeeSurLeRegistre(): void
    {
        $detenteur = $this->makeDetenteur();
        $this->remise($detenteur, 2, '2026-01-10');
        $this->mouvement($detenteur, CleMouvementType::RESTITUTION, 1, '2026-02-01');

        $globale = $this->detentionDe($detenteur);
        $individuelle = $this->service->getDetentionDe($detenteur);

        self::assertSame($globale->solde, $individuelle->solde);
        self::assertEquals($globale->detenteurDepuis, $individuelle->detenteurDepuis);
        self::assertEquals($globale->derniereRemiseLe, $individuelle->derniereRemiseLe);
    }

    public function testUneRestitutionSuperieureAuSoldeEstRefusee(): void
    {
        $detenteur = $this->makeDetenteur();
        $this->remise($detenteur, 1, '2026-01-10');

        $this->expectException(\DomainException::class);

        try {
            $this->mouvement($detenteur, CleMouvementType::RESTITUTION, 2, '2026-02-01');
        } finally {
            self::assertSame(1, $this->service->getSolde($detenteur), 'Aucun mouvement ne doit être persisté.');
        }
    }

    public function testUneQuantiteNulleOuNegativeEstRefusee(): void
    {
        $detenteur = $this->makeDetenteur();

        $this->expectException(\InvalidArgumentException::class);
        $this->remise($detenteur, 0, '2026-01-10');
    }

    public function testLeRegistreNExposeAucuneSuppressionDeMouvement(): void
    {
        foreach (get_class_methods($this->service) as $method) {
            self::assertDoesNotMatchRegularExpression(
                '/^(delete|remove|cancel|annuler|supprimer)/i',
                $method,
                'L\'historique des clés doit rester append-only.',
            );
        }
    }

    /* ── Fabriques ── */

    private function makeDetenteur(string $nom = 'DUPONT', string $prenom = 'Thomas'): Detenteur
    {
        static $n = 0;
        ++$n;

        $detenteur = (new Detenteur())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setEmail(sprintf('cle%d@example.com', $n))
            ->setNumLicence(sprintf('LIC%04d', $n));

        $this->em->persist($detenteur);
        $this->em->flush();

        return $detenteur;
    }

    private function remise(Detenteur $detenteur, int $quantite, string $date): void
    {
        $this->mouvement($detenteur, CleMouvementType::REMISE, $quantite, $date);
    }

    private function mouvement(Detenteur $detenteur, CleMouvementType $type, int $quantite, string $date): void
    {
        $this->service->record(
            $detenteur,
            new CleMouvementData($type, $quantite, new \DateTimeImmutable($date)),
            null,
        );
    }

    private function detentionDe(Detenteur $detenteur): CleDetention
    {
        foreach ($this->service->getDetentions() as $detention) {
            if ($detention->detenteur->getId() === $detenteur->getId()) {
                return $detention;
            }
        }

        self::fail('Aucune détention trouvée pour ce détenteur.');
    }
}
