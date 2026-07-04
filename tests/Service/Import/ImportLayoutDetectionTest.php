<?php declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\Layout\EditionExtractionLayout;
use App\Service\Import\Layout\ImportLayoutResolver;
use App\Service\Import\Layout\LicencesDematerialiseesLayout;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImportLayoutDetectionTest extends KernelTestCase
{
    private function resolver(): ImportLayoutResolver
    {
        self::bootKernel();

        return self::getContainer()->get(ImportLayoutResolver::class);
    }

    /**
     * @param string[] $headers
     * @return array<string, int>
     */
    private function columns(array $headers): array
    {
        $columns = [];
        foreach ($headers as $index => $header) {
            $columns[mb_strtolower(trim($header), 'UTF-8')] = $index;
        }

        return $columns;
    }

    public function testDetecteLeFormatEditionsExtractions(): void
    {
        $columns = $this->columns(['Type licence', 'Nom, prénom', 'Né(e) le', 'Sous catégorie', 'Numéro personne']);

        self::assertInstanceOf(EditionExtractionLayout::class, $this->resolver()->resolve($columns));
    }

    public function testDetecteLeFormatLicencesDematerialisees(): void
    {
        $columns = $this->columns(['Nom', 'Prénom', 'Numéro personne', 'Sous-catégorie', 'Type', 'Date de naissance']);

        self::assertInstanceOf(LicencesDematerialiseesLayout::class, $this->resolver()->resolve($columns));
    }

    public function testFormatInconnuRenvoieNull(): void
    {
        $columns = $this->columns(['Colonne A', 'Colonne B', 'Colonne C']);

        self::assertNull($this->resolver()->resolve($columns));
    }
}
