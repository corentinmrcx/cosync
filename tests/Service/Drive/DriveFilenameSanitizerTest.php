<?php declare(strict_types=1);

namespace App\Tests\Service\Drive;

use App\Service\Drive\DriveFilenameSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Le nom d'un PDF archivé est ce que l'admin lit sur le Drive du club, des années après.
 *
 * Ces tests fixent la règle qui a manqué : un accent se translittère, il ne devient ni un
 * séparateur (« R_EGLEMENT_INT_ERIEUR ») ni un trou (« Noël » → « Nol »).
 */
final class DriveFilenameSanitizerTest extends TestCase
{
    private DriveFilenameSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new DriveFilenameSanitizer();
    }

    public function testUnAccentDevientSaLettreEtNonUnSeparateur(): void
    {
        self::assertSame('reglement_interieur', $this->sanitizer->sanitize('Règlement intérieur'));
        self::assertSame('noel', $this->sanitizer->sanitize('Noël'));
        self::assertSame('francois', $this->sanitizer->sanitize('François'));
    }

    public function testLAccentEnTeteEstTraiteCommeLesAutres(): void
    {
        // Le défaut d'origine se voyait mal ici : la lettre parasite était en tête, donc
        // rognée par le trim — « équipe » ressortait juste, « Réglement » non.
        self::assertSame('equipe', $this->sanitizer->sanitize('Équipe'));
    }

    public function testToutSortEnMinusculesSansEspaceNiPonctuation(): void
    {
        self::assertSame('marcoux_jean_pierre', $this->sanitizer->sanitize('MARCOUX Jean-Pierre'));
        self::assertSame('charte_d_engagement', $this->sanitizer->sanitize("Charte d'engagement"));
    }

    public function testLesLigaturesEtCaracteresEtrangersRestentLisibles(): void
    {
        self::assertSame('coeur_de_ville', $this->sanitizer->sanitize('Cœur de ville'));
        self::assertSame('muller', $this->sanitizer->sanitize('Müller'));
        self::assertSame('strasse', $this->sanitizer->sanitize('Straße'));
    }

    public function testUnTitreSansAucunCaractereUtileNeRendRien(): void
    {
        // Le service appelant doit pouvoir détecter ce cas : un nom de fichier vide
        // écraserait les PDF les uns sur les autres.
        self::assertSame('', $this->sanitizer->sanitize(' --- '));
    }
}
