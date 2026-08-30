<?php declare(strict_types=1);

namespace App\Tests\Service\Relance;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\EnvoiMail;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\EtapeRelance;
use App\Enum\LicenceStatus;
use App\Enum\OrigineEnvoi;
use App\Enum\TypeMail;
use App\Service\Referentiel\ClubSettingsService;
use App\Service\Relance\RelanceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Qui le club relance, et surtout qui il ne relance pas.
 *
 * Le sujet de ces tests est un automate qui écrit à de vraies familles sans que personne ne
 * relise. Chaque condition écartée ici est un mail de trop : à quelqu'un qui a déjà payé, à
 * quelqu'un qu'on n'a jamais contacté, ou — le défaut qui a motivé tout le dispositif — à
 * quelqu'un qu'un admin vient de relancer à la main.
 */
final class RelanceResolverTest extends KernelTestCase
{
    private const AUJOURDHUI = '2026-09-20 09:00:00';

    private EntityManagerInterface $em;
    private Season $season;
    private Category $category;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2026-2027')->setCotisationDefaut(85);
        $this->category = (new Category())->setCode('U16')->setLabel('U16')->setIsEcoleFoot(false);

        $this->em->persist($this->season);
        $this->em->persist($this->category);

        $settings = self::getContainer()->get(ClubSettingsService::class);
        $settings->get()->setRelanceDelaiJours(10)->setRelanceMax(3);
        $settings->enregistrer();
    }

    /** Le cas nominal : dossier non rempli, contacté il y a longtemps, jamais relancé. */
    public function testUnDossierNonRempliEtAncienEstDu(): void
    {
        $licencie = $this->licencie(dernierMail: '2026-09-01 10:00:00');

        $dues = $this->dues();

        self::assertCount(1, $dues);
        self::assertSame($licencie->getUuid(), $dues[0]->licencie->getUuid());
        self::assertSame(EtapeRelance::DOSSIER, $dues[0]->etape);
        self::assertSame(1, $dues[0]->numero);
    }

    /** Un dossier rempli mais non payé se relance aussi — sur le montant, pas sur le lien. */
    public function testUnDossierRempliMaisNonPayeEstRelanceSurLePaiement(): void
    {
        $this->licencie(
            dernierMail: '2026-09-01 10:00:00',
            statut: LicenceStatus::FORM_COMPLETED,
            formCompletedAt: '2026-09-01 11:00:00',
        );

        self::assertSame(EtapeRelance::PAIEMENT, $this->dues()[0]->etape);
    }

    /**
     * **La règle qui tient tout le dispositif.** Le délai part du dernier mail reçu, pas de
     * la date d'inscription : sans cela, un mail automatique serait suivi d'une relance
     * manuelle quelques heures plus tard — ou l'inverse.
     */
    public function testQuelquUnRelanceRecemmentALaMainNEstPlusDu(): void
    {
        $this->licencie(dernierMail: '2026-09-18 14:00:00');

        self::assertSame([], $this->dues());
    }

    /** Un mail d'un autre genre compte aussi : c'est bien « le club lui a écrit ». */
    public function testNImporteQuelMailRecentRepousseLaRelance(): void
    {
        $licencie = $this->licencie(dernierMail: '2026-09-01 10:00:00');
        $this->journaliser($licencie, TypeMail::BOUTIQUE, '2026-09-19 08:00:00');
        $this->em->flush();

        self::assertSame([], $this->dues());
    }

    /** Une licence soldée n'a plus rien à devoir — `estSoldee()`, jamais `=== VALIDATED`. */
    public function testUneLicenceSoldeeNEstJamaisRelancee(): void
    {
        $this->licencie(dernierMail: '2026-09-01 10:00:00', statut: LicenceStatus::A_VALIDER_FFF);

        self::assertSame([], $this->dues());
    }

    /**
     * Relancer quelqu'un qu'on n'a jamais contacté n'est pas une relance : c'est l'envoi
     * initial, et il se décide sur l'écran d'envoi des liens.
     */
    public function testQuelquUnQuiNAJamaisRecuSonLienNEstPasRelance(): void
    {
        $this->licencie(dernierMail: null);

        self::assertSame([], $this->dues());
    }

    /** Sans adresse, la relance se fait au téléphone : la liste ne doit pas prétendre autre chose. */
    public function testSansAdresseEmailPersonneNEstDu(): void
    {
        $this->licencie(dernierMail: '2026-09-01 10:00:00', email: null);

        self::assertSame([], $this->dues());
    }

    /**
     * Sans plafond, le robot écrirait tous les dix jours jusqu'en juin à quelqu'un qui ne
     * paiera pas. Au-delà, la relance n'est plus un mail.
     */
    public function testLePlafondDeRelancesArreteLesEnvois(): void
    {
        $licencie = $this->licencie(dernierMail: '2026-09-01 10:00:00');

        foreach (['2026-08-10 09:00:00', '2026-08-20 09:00:00', '2026-08-30 09:00:00'] as $date) {
            $this->journaliser($licencie, TypeMail::RELANCE_DOSSIER, $date);
        }
        $this->em->flush();

        self::assertSame([], $this->dues());
    }

    /** Le compteur affiché est celui de la relance à venir, pas de celles déjà parties. */
    public function testLeNumeroDeRelanceSuitCellesDejaEnvoyees(): void
    {
        $licencie = $this->licencie(dernierMail: '2026-09-01 10:00:00');
        $this->journaliser($licencie, TypeMail::RELANCE_DOSSIER, '2026-08-20 09:00:00');
        $this->em->flush();

        self::assertSame(2, $this->dues()[0]->numero);
    }

    /** Le plus anciennement contacté d'abord : c'est l'ordre dans lequel on veut traiter. */
    public function testLaListeVaDuContactLePlusAncienAuPlusRecent(): void
    {
        $this->licencie(dernierMail: '2026-09-05 10:00:00', nom: 'RECENT');
        $this->licencie(dernierMail: '2026-08-25 10:00:00', nom: 'ANCIEN');

        $noms = array_map(static fn ($due): string => $due->licencie->getNom(), $this->dues());

        self::assertSame(['ANCIEN', 'RECENT'], $noms);
    }

    /* ── Outils ── */

    /** @return \App\DTO\RelanceDue[] */
    private function dues(): array
    {
        return self::getContainer()->get(RelanceResolver::class)->dues(
            $this->season,
            new \DateTimeImmutable(self::AUJOURDHUI),
        );
    }

    private function licencie(
        ?string $dernierMail,
        LicenceStatus $statut = LicenceStatus::LINK_SENT,
        ?string $formCompletedAt = null,
        ?string $email = 'parent@example.test',
        string $nom = 'MARTIN',
    ): Licencie {
        $licencie = (new Licencie())
            ->setNom($nom)
            ->setPrenom('Kevin')
            ->setDateNaissance(new \DateTimeImmutable('2010-01-01'))
            ->setEmail($email)
            ->setCategory($this->category)
            ->setSeason($this->season);

        // `linkSentAt` et le journal disent deux choses différentes : le premier atteste
        // qu'un lien est parti un jour, le second quand le club a écrit pour la dernière
        // fois. Le resolver lit les deux, les tests les posent donc ensemble.
        if ($dernierMail !== null) {
            $licencie->setLinkSentAt(new \DateTimeImmutable($dernierMail));
        }

        $dossier = (new DossierClub())->setLicencie($licencie)->setStatus($statut);

        if ($formCompletedAt !== null) {
            $dossier->setFormCompletedAt(new \DateTimeImmutable($formCompletedAt));
        }

        $this->em->persist($licencie);
        $this->em->persist($dossier);

        if ($dernierMail !== null) {
            $this->journaliser($licencie, TypeMail::INSCRIPTION_LINK, $dernierMail);
        }

        $this->em->flush();

        return $licencie;
    }

    private function journaliser(Licencie $licencie, TypeMail $type, string $sentAt): void
    {
        $envoi = (new EnvoiMail($type, OrigineEnvoi::ADMIN, (string) $licencie->getEmail()))
            ->rattacherA($licencie)
            ->setSentAt(new \DateTimeImmutable($sentAt));

        $this->em->persist($envoi);
    }
}
