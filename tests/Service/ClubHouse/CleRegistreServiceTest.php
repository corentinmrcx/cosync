<?php declare(strict_types=1);

namespace App\Tests\Service\ClubHouse;

use App\DTO\CleDetention;
use App\DTO\CleMouvementData;
use App\Entity\CleMouvement;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Enum\CleMouvementType;
use App\Repository\CleMouvementRepository;
use App\Service\ClubHouse\CleRegistreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le registre des clés dérive la détention de l'historique : ces tests verrouillent
 * le calcul du solde, la date « détenteur depuis » et le caractère append-only.
 */
final class CleRegistreServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CleRegistreService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        /** @var CleMouvementRepository $repo */
        $repo = $this->em->getRepository(CleMouvement::class);
        $this->service = new CleRegistreService($repo, $this->em);
    }

    public function testLeSoldeRetombeAZeroApresRestitutionEtPerte(): void
    {
        $season    = $this->makeSeason();
        $dirigeant = $this->makeDirigeant($season);

        $this->remise($dirigeant, 2, '2026-01-10');
        $this->mouvement($dirigeant, CleMouvementType::RESTITUTION, 1, '2026-02-01');
        $this->mouvement($dirigeant, CleMouvementType::PERTE, 1, '2026-03-01');

        $detention = $this->detentionDe($season, $dirigeant);

        self::assertSame(0, $detention->solde);
        self::assertNull($detention->detenteurDepuis, 'Sans clé détenue, la date de détention est effacée.');
        self::assertSame(2, $detention->remises);
        self::assertSame(1, $detention->restitutions);
        self::assertSame(1, $detention->pertes);
        self::assertFalse($detention->estDetenteur());
    }

    public function testDetenteurDepuisEstLaDateDeLaRemiseQuiRelanceLaDetention(): void
    {
        $season    = $this->makeSeason();
        $dirigeant = $this->makeDirigeant($season);

        $this->remise($dirigeant, 1, '2026-01-10');
        $this->mouvement($dirigeant, CleMouvementType::RESTITUTION, 1, '2026-02-01');
        $this->remise($dirigeant, 1, '2026-05-20');

        $detention = $this->detentionDe($season, $dirigeant);

        self::assertSame(1, $detention->solde);
        self::assertNotNull($detention->detenteurDepuis);
        self::assertSame('2026-05-20', $detention->detenteurDepuis->format('Y-m-d'));
    }

    public function testLesStatsAgregentCirculationDetenteursEtPertes(): void
    {
        $season = $this->makeSeason();

        $detenteur = $this->makeDirigeant($season, 'DUPONT', 'Thomas');
        $this->remise($detenteur, 2, '2026-01-10');

        $ancien = $this->makeDirigeant($season, 'MARTIN', 'Kevin');
        $this->remise($ancien, 1, '2026-01-10');
        $this->mouvement($ancien, CleMouvementType::PERTE, 1, '2026-04-01');

        $stats = $this->service->getStats($season);

        self::assertSame(2, $stats->clesEnCirculation);
        self::assertSame(1, $stats->nbDetenteurs, 'Seules les personnes au solde positif comptent.');
        self::assertSame(1, $stats->clesPerdues);
        self::assertSame(0, $stats->nbAttestationsSignees);
        self::assertSame(1, $stats->nbAttestationsManquantes);
    }

    public function testUneAttestationSigneeEstComptabiliseePourLesDetenteurs(): void
    {
        $season    = $this->makeSeason();
        $dirigeant = $this->makeDirigeant($season);
        $this->remise($dirigeant, 1, '2026-01-10');
        $this->marquerSignee($dirigeant, '2026-01-11');

        $stats = $this->service->getStats($season);

        self::assertSame(1, $stats->nbAttestationsSignees);
        self::assertSame(0, $stats->nbAttestationsManquantes);
    }

    public function testUneCleRemiseApresSignatureRendLAttestationARenouveler(): void
    {
        $season    = $this->makeSeason();
        $dirigeant = $this->makeDirigeant($season);
        $this->remise($dirigeant, 1, '2026-01-10');
        $this->marquerSignee($dirigeant, '2026-01-11');
        $this->remise($dirigeant, 1, '2026-03-01');

        $detention = $this->detentionDe($season, $dirigeant);

        self::assertSame(2, $detention->solde);
        self::assertTrue($detention->aSigne());
        self::assertTrue($detention->doitResigner(), 'Le nombre attesté est dépassé.');
        self::assertFalse($detention->attestationAJour());

        $stats = $this->service->getStats($season);
        self::assertSame(0, $stats->nbAttestationsSignees, 'Une attestation dépassée ne compte pas.');
        self::assertSame(1, $stats->nbAttestationsManquantes);
    }

    public function testUneRestitutionApresSignatureNeDeclenchePasDeResignature(): void
    {
        $season    = $this->makeSeason();
        $dirigeant = $this->makeDirigeant($season);
        $this->remise($dirigeant, 2, '2026-01-10');
        $this->marquerSignee($dirigeant, '2026-01-11');
        $this->mouvement($dirigeant, CleMouvementType::RESTITUTION, 1, '2026-03-01');

        $detention = $this->detentionDe($season, $dirigeant);

        self::assertSame(1, $detention->solde);
        self::assertFalse($detention->doitResigner(), 'Rendre une clé ne périme pas l\'attestation.');
        self::assertTrue($detention->attestationAJour());
    }

    public function testUnNonSignataireNEstPasMarqueARenouveler(): void
    {
        $season    = $this->makeSeason();
        $dirigeant = $this->makeDirigeant($season);
        $this->remise($dirigeant, 1, '2026-01-10');

        $detention = $this->detentionDe($season, $dirigeant);

        self::assertFalse($detention->aSigne());
        self::assertFalse($detention->doitResigner(), '« Non signée » et « à renouveler » sont deux états distincts.');
    }

    public function testLaDetentionIndividuelleEgaleCelleCalculeeSurLaSaison(): void
    {
        $season    = $this->makeSeason();
        $dirigeant = $this->makeDirigeant($season);
        $this->remise($dirigeant, 2, '2026-01-10');
        $this->mouvement($dirigeant, CleMouvementType::RESTITUTION, 1, '2026-02-01');

        $parSaison    = $this->detentionDe($season, $dirigeant);
        $individuelle = $this->service->getDetentionDe($dirigeant);

        self::assertSame($parSaison->solde, $individuelle->solde);
        self::assertEquals($parSaison->detenteurDepuis, $individuelle->detenteurDepuis);
        self::assertEquals($parSaison->derniereRemiseLe, $individuelle->derniereRemiseLe);
    }

    public function testUneRestitutionSuperieureAuSoldeEstRefusee(): void
    {
        $season    = $this->makeSeason();
        $dirigeant = $this->makeDirigeant($season);
        $this->remise($dirigeant, 1, '2026-01-10');

        $this->expectException(\DomainException::class);

        try {
            $this->mouvement($dirigeant, CleMouvementType::RESTITUTION, 2, '2026-02-01');
        } finally {
            self::assertSame(1, $this->service->getSolde($dirigeant), 'Aucun mouvement ne doit être persisté.');
        }
    }

    public function testUneQuantiteNulleOuNegativeEstRefusee(): void
    {
        $season    = $this->makeSeason();
        $dirigeant = $this->makeDirigeant($season);

        $this->expectException(\InvalidArgumentException::class);
        $this->remise($dirigeant, 0, '2026-01-10');
    }

    public function testLesMouvementsSontCloisonnesParSaison(): void
    {
        $saison1 = $this->makeSeason('2025-2026');
        $saison2 = $this->makeSeason('2026-2027');

        $this->remise($this->makeDirigeant($saison1), 1, '2026-01-10');

        self::assertCount(1, $this->service->getDetentions($saison1));
        self::assertCount(0, $this->service->getDetentions($saison2));
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

    private function makeSeason(string $label = '2025-2026'): Season
    {
        $season = (new Season())->setLabel($label)->setCotisationDefaut(85);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function makeDirigeant(Season $season, string $nom = 'DUPONT', string $prenom = 'Thomas'): Dirigeant
    {
        static $n = 0;
        ++$n;

        $dirigeant = (new Dirigeant())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setEmail(sprintf('cle%d@example.com', $n))
            ->setSeason($season);
        $this->em->persist($dirigeant);
        $this->em->flush();

        return $dirigeant;
    }

    private function marquerSignee(Dirigeant $dirigeant, string $date): void
    {
        $dirigeant
            ->setAttestationCleSignePath('drive-id-123')
            ->setAttestationCleSignedAt(new \DateTimeImmutable($date));
        $this->em->flush();
    }

    private function remise(Dirigeant $dirigeant, int $quantite, string $date): void
    {
        $this->mouvement($dirigeant, CleMouvementType::REMISE, $quantite, $date);
    }

    private function mouvement(Dirigeant $dirigeant, CleMouvementType $type, int $quantite, string $date): void
    {
        $this->service->record(
            $dirigeant,
            new CleMouvementData($type, $quantite, new \DateTimeImmutable($date)),
            null,
        );
    }

    private function detentionDe(Season $season, Dirigeant $dirigeant): CleDetention
    {
        foreach ($this->service->getDetentions($season) as $detention) {
            if ($detention->dirigeant->getUuid()->equals($dirigeant->getUuid())) {
                return $detention;
            }
        }

        self::fail('Aucune détention trouvée pour ce dirigeant.');
    }
}
