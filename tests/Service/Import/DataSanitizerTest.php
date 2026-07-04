<?php declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\DataSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataSanitizerTest extends TestCase
{
    private DataSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new DataSanitizer();
    }

    #[DataProvider('sousCategorieProvider')]
    public function testSanitizeSousCategorie(string $raw, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer->sanitizeSousCategorie($raw));
    }

    /** @return iterable<string, array{string, string}> */
    public static function sousCategorieProvider(): iterable
    {
        yield 'U9 avec parenthèses'    => ['U9 (- 9 ans)', 'U9'];
        yield 'féminin colle le suffixe' => ['U11 F (- 11 ans F)', 'U11F'];
        yield 'senior'                 => ['Senior', 'SENIOR'];
        yield 'vétéran accentué'       => ['Vétéran', 'VETERAN'];
        yield 'alias Senior U20 → SENIOR' => ['Senior U20 (- 20 ans)', 'SENIOR'];
    }

    /**
     * @param array{nom: string, prenom: string} $expected
     */
    #[DataProvider('nomPrenomProvider')]
    public function testSplitNomPrenom(string $raw, array $expected): void
    {
        self::assertSame($expected, $this->sanitizer->splitNomPrenom($raw));
    }

    /** @return iterable<string, array{string, array{nom: string, prenom: string}}> */
    public static function nomPrenomProvider(): iterable
    {
        yield 'simple'              => ['ARDINAT Quentin', ['nom' => 'ARDINAT', 'prenom' => 'Quentin']];
        yield 'nom composé'         => ['SAINT LOUIS Florent', ['nom' => 'SAINT LOUIS', 'prenom' => 'Florent']];
        yield 'nom à particule'     => ['DE SOUSA BRITO Jonathan', ['nom' => 'DE SOUSA BRITO', 'prenom' => 'Jonathan']];
        yield 'prénom composé'      => ['VAUQUELIN Camille Florent', ['nom' => 'VAUQUELIN', 'prenom' => 'Camille Florent']];
        yield 'nom seul'            => ['MARTIN', ['nom' => 'MARTIN', 'prenom' => '']];
        yield 'tout en majuscules'  => ['MARTIN JEAN', ['nom' => 'MARTIN', 'prenom' => 'Jean']];
    }

    public function testSanitizeSeparateNomPrenom(): void
    {
        $result = $this->sanitizer->sanitizeSeparateNomPrenom('guillouart boulland', 'lydie');

        self::assertSame('GUILLOUART BOULLAND', $result['nom']);
        self::assertSame('Lydie', $result['prenom']);
    }
}
