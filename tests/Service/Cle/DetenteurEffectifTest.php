<?php declare(strict_types=1);

namespace App\Tests\Service\Cle;

use App\DTO\CleRegistreRow;
use App\Entity\CleMouvement;
use App\Entity\Detenteur;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Enum\CleMouvementType;
use App\Service\Cle\CleRegistrePresenter;
use App\Service\Cle\DetenteurEffectifResolver;
use App\Service\Cle\DetenteurLicenceSynchronizer;
use App\Service\Cle\DetenteurService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le rapprochement registre ↔ effectif, et ce qui le rendait fragile en prod : une
 * fiche entrée au registre avant le premier import n'avait pas de numéro de licence,
 * le rapprochement retombait sur le nom, et l'import suivant réécrivait ce nom à
 * l'orthographe FootClubs. La détentrice sortait « hors effectif » alors que ses clés
 * lui avaient bien été remises en tant que dirigeante.
 */
final class DetenteurEffectifTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DetenteurEffectifResolver $resolver;
    private DetenteurLicenceSynchronizer $licenceSync;
    private CleRegistrePresenter $presenter;
    private DetenteurService $detenteurService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->resolver = self::getContainer()->get(DetenteurEffectifResolver::class);
        $this->licenceSync = self::getContainer()->get(DetenteurLicenceSynchronizer::class);
        $this->presenter = self::getContainer()->get(CleRegistrePresenter::class);
        $this->detenteurService = self::getContainer()->get(DetenteurService::class);
    }

    /** Le cas de prod : « Marlène » au registre, « Marlene » à l'effectif après import. */
    public function testUnAccentPerduParLImportNeSortPasLaDetentriceDeLEffectif(): void
    {
        $season = $this->makeSeason();

        $detenteur = $this->makeDetenteur('LAGRANGE', 'Marlène');
        $this->makeDirigeant($season, 'LAGRANGE', 'Marlene', '9603286210');
        $this->remise($detenteur, 1);

        self::assertFalse(
            $this->ligneDe($season, $detenteur)->horsEffectif(),
            'Marlène et Marlene sont la même personne.',
        );
    }

    /** Les deux sens du rapprochement doivent tomber d'accord, sans quoi le sélecteur la propose deux fois. */
    public function testLeRapprochementTombeDAccordDansLesDeuxSens(): void
    {
        $season = $this->makeSeason();

        $detenteur = $this->makeDetenteur('LAGRANGE', 'Marlène');
        $dirigeant = $this->makeDirigeant($season, 'LAGRANGE', 'Marlene', '9603286210');

        self::assertSame($detenteur->getId(), $this->resolver->detenteurDe($dirigeant)?->getId());
        self::assertSame(
            [$detenteur->getId() => $dirigeant],
            $this->resolver->pourSaison($season, [$detenteur]),
        );
    }

    /** Deux homonymes à l'accent près ne font qu'un : leurs clés ne doivent pas se répartir sur deux lignes. */
    public function testLeRegistreRefuseUnDoublonQuiNeDiffereQueParLAccent(): void
    {
        $this->makeDetenteur('LAGRANGE', 'Marlène');

        $this->expectException(\DomainException::class);

        $this->detenteurService->creerExterieur('LAGRANGE', 'Marlene', null, null, null);
    }

    public function testLaLicenceEstReposeeSurUneFicheEntreeAuRegistreAvantLImport(): void
    {
        $season = $this->makeSeason();

        // L'ordre de la prod : dirigeant saisi à la main sans licence, clé remise,
        // puis import FootClubs qui apporte la licence et sa propre orthographe.
        $detenteur = $this->makeDetenteur('LAGRANGE', 'Marlène');
        $this->remise($detenteur, 1);
        $this->makeDirigeant($season, 'LAGRANGE', 'Marlene', '9603286210');

        self::assertNull($detenteur->getNumLicence());

        $this->licenceSync->pourSaison($season);

        self::assertSame(
            '9603286210',
            $detenteur->getNumLicence(),
            'Le registre doit retrouver son identifiant stable et cesser de dépendre de l\'orthographe.',
        );
    }

    public function testLaSynchronisationNEcrasePasUneLicenceDejaPosee(): void
    {
        $season = $this->makeSeason();

        $detenteur = $this->makeDetenteur('PARTI', 'Jean', 'ANCIENNE-LIC');
        $this->makeDirigeant($season, 'PARTI', 'Jean', 'NOUVELLE-LIC');

        $this->licenceSync->pourSaison($season);

        self::assertSame('ANCIENNE-LIC', $detenteur->getNumLicence());
    }

    /** Sans ce garde-fou, findByNumLicence() ne saurait plus laquelle des deux fiches rendre. */
    public function testUneLicenceDejaPorteeAuRegistreNEstPasReposeeAilleurs(): void
    {
        $season = $this->makeSeason();

        $this->makeDetenteur('MARTIN', 'Kevin', 'LIC-PARTAGEE');
        $homonyme = $this->makeDetenteur('MARTIN', 'Kévin');
        $this->makeDirigeant($season, 'MARTIN', 'Kevin', 'LIC-PARTAGEE');

        $this->licenceSync->pourSaison($season);

        self::assertNull($homonyme->getNumLicence());
    }

    public function testUnDetenteurSansEquivalentALEffectifResteHorsEffectif(): void
    {
        $season = $this->makeSeason();

        $detenteur = $this->makeDetenteur('MAIRIE', 'Service');
        $this->remise($detenteur, 1);

        $this->licenceSync->pourSaison($season);

        self::assertNull($detenteur->getNumLicence());
        self::assertTrue($this->ligneDe($season, $detenteur)->horsEffectif());
    }

    /* ── Fabriques ── */

    private function makeSeason(string $label = '2026-2027'): Season
    {
        $season = (new Season())->setLabel($label)->setCotisationDefaut(85);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function makeDetenteur(string $nom, string $prenom, ?string $numLicence = null): Detenteur
    {
        $detenteur = (new Detenteur())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setNumLicence($numLicence);

        $this->em->persist($detenteur);
        $this->em->flush();

        return $detenteur;
    }

    private function makeDirigeant(Season $season, string $nom, string $prenom, ?string $numLicence): Dirigeant
    {
        $dirigeant = (new Dirigeant())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setNumLicence($numLicence)
            ->setSeason($season);

        $this->em->persist($dirigeant);
        $this->em->flush();

        return $dirigeant;
    }

    private function remise(Detenteur $detenteur, int $quantite): void
    {
        $mouvement = (new CleMouvement())
            ->setDetenteur($detenteur)
            ->setType(CleMouvementType::REMISE)
            ->setQuantite($quantite)
            ->setDateMouvement(new \DateTimeImmutable('2026-08-15'));

        $this->em->persist($mouvement);
        $this->em->flush();
    }

    private function ligneDe(Season $season, Detenteur $detenteur): CleRegistreRow
    {
        foreach ($this->presenter->lignes($season) as $ligne) {
            if ($ligne->detenteur()->getId() === $detenteur->getId()) {
                return $ligne;
            }
        }

        self::fail('Aucune ligne de registre trouvée pour ce détenteur.');
    }
}
