<?php declare(strict_types=1);

namespace App\Tests\Service\Pdf;

use App\Service\Pdf\MontantEnLettresFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le montant en toutes lettres d'une attestation de paiement.
 *
 * Ces tests existent parce que la première version déléguait à
 * `NumberFormatter::SPELLOUT` : l'image PHP embarquant un ICU réduit à l'anglais, la
 * conversion rendait « one hundred twenty » **sans lever la moindre erreur**. Le piège
 * n'est pas l'orthographe française, c'est le repli silencieux.
 */
final class MontantEnLettresFormatterTest extends TestCase
{
    private MontantEnLettresFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MontantEnLettresFormatter();
    }

    public function testLeMontantEstEcritEnFrancais(): void
    {
        self::assertSame('cent vingt euros', $this->formatter->format('120.00'));
    }

    #[DataProvider('nombres')]
    public function testEpelleLesNombresSelonLesReglesFrancaises(int $nombre, string $attendu): void
    {
        self::assertSame($attendu, $this->formatter->epeler($nombre));
    }

    /** @return iterable<string, array{int, string}> */
    public static function nombres(): iterable
    {
        yield 'zéro' => [0, 'zéro'];
        yield 'unité' => [7, 'sept'];
        yield 'seize, dernier mot simple' => [16, 'seize'];
        yield 'dix-sept se compose' => [17, 'dix-sept'];
        yield 'le « et » de vingt et un' => [21, 'vingt et un'];
        yield 'soixante-dix' => [70, 'soixante-dix'];
        yield 'le « et » survit à soixante et onze' => [71, 'soixante et onze'];
        yield 'mais pas au-delà' => [72, 'soixante-douze'];
        yield 'quatre-vingts prend son s seul' => [80, 'quatre-vingts'];
        yield 'et le perd dès qu\'il est suivi' => [81, 'quatre-vingt-un'];
        yield 'quatre-vingt-dix ne dit jamais « et »' => [91, 'quatre-vingt-onze'];
        yield 'cent seul' => [100, 'cent'];
        yield 'cent multiplié prend son s' => [200, 'deux cents'];
        yield 'sauf suivi d\'un autre nombre' => [201, 'deux cent un'];
        yield 'mille ne se dit pas « un mille »' => [1000, 'mille'];
        yield 'mille est invariable' => [2000, 'deux mille'];
        yield 'vingt reste invariable devant mille' => [80000, 'quatre-vingt mille'];
        yield 'cent aussi' => [200000, 'deux cent mille'];
        yield 'million s\'accorde' => [2_000_000, 'deux millions'];
    }

    public function testLesCentimesSontDits(): void
    {
        self::assertSame('cent vingt euros et cinquante centimes', $this->formatter->format('120.50'));
        self::assertSame('quatre-vingt-quinze euros et soixante-quinze centimes', $this->formatter->format('95.75'));
    }

    public function testLeSingulierEstRespecte(): void
    {
        self::assertSame('un euro', $this->formatter->format('1.00'));
        self::assertSame('zéro euro et un centime', $this->formatter->format('0.01'));
    }

    /**
     * (float) 120.29 * 100 vaut 12028.999… : une troncature ferait disparaître un
     * centime entre le chiffre imprimé et sa transcription en lettres.
     */
    public function testLeCentimeNEstPasPerduParLArrondiFlottant(): void
    {
        self::assertSame('cent vingt euros et vingt-neuf centimes', $this->formatter->format('120.29'));
    }
}
