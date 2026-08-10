<?php declare(strict_types=1);

namespace App\Tests\Service\Cle;

use App\DTO\CleRegistreRow;
use App\Entity\AttestationCle;
use App\Entity\CleMouvement;
use App\Entity\Detenteur;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Enum\CleAttestationEtat;
use App\Enum\CleMouvementType;
use App\Service\Cle\CleRegistrePresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Le point de rencontre des deux échelles de temps : la détention vit au niveau du
 * club, l'engagement signé vit dans une saison. Ces tests verrouillent ce que le
 * registre affiche de cette rencontre — c'est là que se joue la question juridique
 * « qui détient une clé, et qui s'est engagé cette année ».
 */
final class CleRegistrePresenterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CleRegistrePresenter $presenter;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->presenter = self::getContainer()->get(CleRegistrePresenter::class);
    }

    /** Le cœur du renouvellement annuel. */
    public function testUneAttestationSigneeLaSaisonPrecedenteNeVautPasPourLaSuivante(): void
    {
        $ancienne = $this->makeSeason('2025-2026');
        $nouvelle = $this->makeSeason('2026-2027');

        $detenteur = $this->makeDetenteur();
        $this->remise($detenteur, 1, '2025-09-01');
        $this->signer($detenteur, $ancienne, '2025-09-02');

        self::assertSame(CleAttestationEtat::SIGNEE, $this->ligneDe($ancienne, $detenteur)->etatAttestation());
        self::assertSame(
            CleAttestationEtat::NON_SIGNEE,
            $this->ligneDe($nouvelle, $detenteur)->etatAttestation(),
            'L\'engagement se rejoue chaque saison.',
        );
    }

    public function testLaDetentionElleNeSeRejouePasChaqueSaison(): void
    {
        $this->makeSeason('2025-2026');
        $nouvelle = $this->makeSeason('2026-2027');

        $detenteur = $this->makeDetenteur();
        $this->remise($detenteur, 2, '2025-09-01');

        $ligne = $this->ligneDe($nouvelle, $detenteur);

        self::assertSame(2, $ligne->detention->solde, 'Les clés remises l\'an dernier sont toujours dehors.');
        self::assertTrue($ligne->estDetenteur());
    }

    public function testUneCleRemiseApresSignatureRendLAttestationARenouveler(): void
    {
        $season = $this->makeSeason();
        $detenteur = $this->makeDetenteur();

        $this->remise($detenteur, 1, '2026-01-10');
        $this->signer($detenteur, $season, '2026-01-11');
        $this->remise($detenteur, 1, '2026-03-01');

        $ligne = $this->ligneDe($season, $detenteur);

        self::assertSame(2, $ligne->detention->solde);
        self::assertSame(CleAttestationEtat::A_RENOUVELER, $ligne->etatAttestation());
        self::assertFalse($ligne->attestationAJour(), 'Le nombre attesté est dépassé.');
        self::assertTrue($ligne->attendSignature());
    }

    public function testUneRestitutionApresSignatureNeDeclenchePasDeResignature(): void
    {
        $season = $this->makeSeason();
        $detenteur = $this->makeDetenteur();

        $this->remise($detenteur, 2, '2026-01-10');
        $this->signer($detenteur, $season, '2026-01-11');
        $this->mouvement($detenteur, CleMouvementType::RESTITUTION, 1, '2026-03-01');

        $ligne = $this->ligneDe($season, $detenteur);

        self::assertSame(1, $ligne->detention->solde);
        self::assertTrue($ligne->attestationAJour(), 'Rendre une clé ne périme pas l\'attestation.');
    }

    public function testUnLienEnvoyeSeDistingueDUneAbsenceDeDemande(): void
    {
        $season = $this->makeSeason();
        $detenteur = $this->makeDetenteur();
        $this->remise($detenteur, 1, '2026-01-10');

        self::assertSame(CleAttestationEtat::NON_SIGNEE, $this->ligneDe($season, $detenteur)->etatAttestation());

        $this->demander($detenteur, $season, '+30 days');

        self::assertSame(CleAttestationEtat::LIEN_ENVOYE, $this->ligneDe($season, $detenteur)->etatAttestation());
    }

    public function testUnLienExpireRetombeEnNonSignee(): void
    {
        $season = $this->makeSeason();
        $detenteur = $this->makeDetenteur();
        $this->remise($detenteur, 1, '2026-01-10');

        $this->demander($detenteur, $season, '-1 day');

        self::assertSame(CleAttestationEtat::NON_SIGNEE, $this->ligneDe($season, $detenteur)->etatAttestation());
    }

    /**
     * Quelqu'un est parti du club sans rendre son trousseau : le registre doit le
     * garder visible plutôt que de le faire disparaître avec l'effectif.
     */
    public function testUnDetenteurQuiNEstPlusDirigeantResteVisibleEnAlerte(): void
    {
        $ancienne = $this->makeSeason('2025-2026');
        $nouvelle = $this->makeSeason('2026-2027');

        $detenteur = $this->makeDetenteur('PARTI', 'Jean', 'LIC-PARTI');
        $this->makeDirigeant($ancienne, 'PARTI', 'Jean', 'LIC-PARTI');
        $this->remise($detenteur, 1, '2025-09-01');

        self::assertFalse($this->ligneDe($ancienne, $detenteur)->horsEffectif());

        $ligne = $this->ligneDe($nouvelle, $detenteur);

        self::assertTrue($ligne->horsEffectif(), 'Ses clés sont toujours dehors : il reste au registre.');
        self::assertTrue($ligne->estDetenteur());
        self::assertSame(1, $this->presenter->stats($this->presenter->lignes($nouvelle))->nbHorsEffectif);
    }

    public function testQuiARenduSesClesNAttendAucuneSignature(): void
    {
        $season = $this->makeSeason();
        $detenteur = $this->makeDetenteur();

        $this->remise($detenteur, 1, '2026-01-10');
        $this->mouvement($detenteur, CleMouvementType::RESTITUTION, 1, '2026-02-01');

        $ligne = $this->ligneDe($season, $detenteur);

        self::assertFalse($ligne->estDetenteur());
        self::assertFalse($ligne->attendSignature());
        self::assertSame([], $this->presenter->enAttenteDeSignature($this->presenter->lignes($season)));
    }

    public function testLesStatsAgregentCirculationDetenteursEtPertes(): void
    {
        $season = $this->makeSeason();

        $detenteur = $this->makeDetenteur('DUPONT', 'Thomas');
        $this->remise($detenteur, 2, '2026-01-10');

        $ancien = $this->makeDetenteur('MARTIN', 'Kevin');
        $this->remise($ancien, 1, '2026-01-10');
        $this->mouvement($ancien, CleMouvementType::PERTE, 1, '2026-04-01');

        $stats = $this->presenter->stats($this->presenter->lignes($season));

        self::assertSame(2, $stats->clesEnCirculation);
        self::assertSame(1, $stats->nbDetenteurs, 'Seules les personnes au solde positif comptent.');
        self::assertSame(1, $stats->clesPerdues);
        self::assertSame(0, $stats->nbAttestationsSignees);
        self::assertSame(1, $stats->nbAttestationsManquantes);
    }

    /* ── Fabriques ── */

    private function makeSeason(string $label = '2025-2026'): Season
    {
        $season = (new Season())->setLabel($label)->setCotisationDefaut(85);
        $this->em->persist($season);
        $this->em->flush();

        return $season;
    }

    private function makeDetenteur(string $nom = 'DUPONT', string $prenom = 'Thomas', ?string $numLicence = null): Detenteur
    {
        static $n = 0;
        ++$n;

        $detenteur = (new Detenteur())
            ->setNom($nom)
            ->setPrenom($prenom)
            ->setEmail(sprintf('presenter%d@example.com', $n))
            ->setNumLicence($numLicence ?? sprintf('LIC%04d', $n));

        $this->em->persist($detenteur);
        $this->em->flush();

        return $detenteur;
    }

    private function makeDirigeant(Season $season, string $nom, string $prenom, string $numLicence): Dirigeant
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

    private function remise(Detenteur $detenteur, int $quantite, string $date): void
    {
        $this->mouvement($detenteur, CleMouvementType::REMISE, $quantite, $date);
    }

    private function mouvement(Detenteur $detenteur, CleMouvementType $type, int $quantite, string $date): void
    {
        $mouvement = (new CleMouvement())
            ->setDetenteur($detenteur)
            ->setType($type)
            ->setQuantite($quantite)
            ->setDateMouvement(new \DateTimeImmutable($date));

        $this->em->persist($mouvement);
        $this->em->flush();
    }

    private function demander(Detenteur $detenteur, Season $season, string $expiration): AttestationCle
    {
        $attestation = (new AttestationCle())
            ->setDetenteur($detenteur)
            ->setSeason($season)
            ->setDemandeEnvoyeeLe(new \DateTimeImmutable())
            ->setTokenExpiresAt(new \DateTimeImmutable($expiration));

        $this->em->persist($attestation);
        $this->em->flush();

        return $attestation;
    }

    private function signer(Detenteur $detenteur, Season $season, string $date): void
    {
        $attestation = $this->demander($detenteur, $season, '+30 days');

        $attestation
            ->setSignedAt(new \DateTimeImmutable($date))
            ->setTokenExpiresAt(null)
            ->setDrivePath('drive-id-' . $attestation->getUuid());

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
