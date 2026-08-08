<?php declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Dirigeant;
use App\Entity\Season;
use PHPUnit\Framework\TestCase;

/**
 * L'attestation de remise de clés ne concerne qu'un sous-ensemble de dirigeants
 * (les détenteurs de clés). Elle ne doit donc jamais entrer dans la complétude
 * du dossier dirigeant, ni partager son token.
 *
 * On teste ici la moitié que l'entité juge seule ; les documents à signer, qui
 * dépendent d'une requête, sont couverts par DirigeantDossierCompletionTest.
 */
final class DirigeantAttestationCleTest extends TestCase
{
    public function testLAttestationNInfluencePasLaCompletudeDuDossier(): void
    {
        $sans = $this->makeDirigeantComplet();
        $avec = $this->makeDirigeantComplet();

        $avec->setAttestationCleSignePath('drive-id-123')
             ->setAttestationCleSignedAt(new \DateTimeImmutable())
             ->setAttestationCleTokenExpiresAt(new \DateTimeImmutable('+30 days'));

        self::assertTrue($sans->isBaseFormComplete());
        self::assertSame($sans->isBaseFormComplete(), $avec->isBaseFormComplete());
    }

    public function testUnDossierIncompletLeResteMalgreUneAttestationSignee(): void
    {
        $dirigeant = (new Dirigeant())->setNom('DUPONT')->setPrenom('Thomas')->setSeason(new Season());
        $dirigeant->setAttestationCleSignePath('drive-id-123');

        self::assertFalse($dirigeant->isBaseFormComplete());
    }

    public function testHasSignedAttestationCleSuitLePath(): void
    {
        $dirigeant = new Dirigeant();

        self::assertFalse($dirigeant->hasSignedAttestationCle());

        $dirigeant->setAttestationCleSignePath('/var/pdfs/abc_attestation_cle.pdf');
        self::assertTrue($dirigeant->hasSignedAttestationCle(), 'Vrai dès le chemin local, avant upload Drive.');
    }

    public function testLeTokenDAttestationEstIndependantDeCeluiDuDossier(): void
    {
        $dirigeant = new Dirigeant();
        $dirigeant->setFormTokenExpiresAt(new \DateTimeImmutable('+30 days'));

        self::assertTrue($dirigeant->isFormTokenValid());
        self::assertFalse($dirigeant->isAttestationCleTokenValid(), 'Le token du dossier ne vaut pas pour l\'attestation.');

        $dirigeant->setAttestationCleTokenExpiresAt(new \DateTimeImmutable('+30 days'));
        self::assertTrue($dirigeant->isAttestationCleTokenValid());

        $dirigeant->setAttestationCleTokenExpiresAt(new \DateTimeImmutable('-1 day'));
        self::assertFalse($dirigeant->isAttestationCleTokenValid());
        self::assertTrue($dirigeant->isFormTokenValid(), 'Le token du dossier reste valide.');
    }

    private function makeDirigeantComplet(): Dirigeant
    {
        return (new Dirigeant())
            ->setNom('DUPONT')
            ->setPrenom('Thomas')
            ->setSeason(new Season())
            ->setTailleHaut('L')
            ->setTailleBas('M')
            ->setPointure('42')
            ->setAutorisationPhoto(true)
            ->setVolontaireTransport(false);
    }
}
