<?php declare(strict_types=1);

namespace App\Tests\Service\Referentiel;

use App\Entity\Taille;
use App\Enum\TailleType;
use App\Repository\TailleRepository;
use App\Service\Referentiel\TailleReferentiel;
use PHPUnit\Framework\TestCase;

/**
 * Le référentiel sert deux publics qu'il ne faut pas confondre : ce qu'une personne sait
 * dire d'elle-même, et ce que le fournisseur a étiqueté sur le carton.
 */
final class TailleReferentielTest extends TestCase
{
    public function testLeStockVoitToutesLesDeclinaisonsDuType(): void
    {
        $referentiel = $this->referentiel();

        self::assertSame(['S', 'M', 'XL', '10 ans', '128', 'L enfant'], $referentiel->pourLeStock(TailleType::VETEMENT));
        self::assertSame(['40', '41'], $referentiel->pourLeStock(TailleType::POINTURE));
    }

    /**
     * Un parent ne sait pas si le maillot de son enfant est étiqueté 128 ou « L enfant » :
     * il connaît son âge. Ces déclinaisons resteraient sans réponse dans le formulaire
     * public, et fausseraient la taille déduite pour la dotation.
     */
    public function testLesFormulairesNeProposentQueLesTaillesQuOnSaitDireDeSoi(): void
    {
        $groupes = $this->referentiel()->groupesProposes(TailleType::VETEMENT);

        self::assertSame([
            ['label' => 'Tailles adultes', 'options' => ['S', 'M', 'XL']],
            ['label' => 'Tailles enfants', 'options' => ['10 ans']],
        ], $groupes);
    }

    /** Un groupe sans intitulé — les pointures — se rend à plat, comme le fait Symfony. */
    public function testLesChoixSymfonySontGroupesOuAPlatSelonLeReferentiel(): void
    {
        $referentiel = $this->referentiel();

        self::assertSame([
            'Tailles adultes' => ['S' => 'S', 'M' => 'M', 'XL' => 'XL'],
            'Tailles enfants' => ['10 ans' => '10 ans'],
        ], $referentiel->choixGroupes(TailleType::VETEMENT));

        self::assertSame(['40' => '40', '41' => '41'], $referentiel->choixGroupes(TailleType::POINTURE));
    }

    public function testLOrdreDAffichageSuitLeReferentielPuisLesNombres(): void
    {
        $referentiel = $this->referentiel();

        $tailles = ['128', 'XL', '10 ans', 'S'];
        usort($tailles, $referentiel->comparer(...));
        self::assertSame(['S', 'XL', '10 ans', '128'], $tailles);

        // Hors référentiel : les nombres se rangent en nombres, pas en lettres.
        $inconnues = ['110', '9', '38'];
        usort($inconnues, $referentiel->comparer(...));
        self::assertSame(['9', '38', '110'], $inconnues);
    }

    private function referentiel(): TailleReferentiel
    {
        $ligne = static function (string $libelle, TailleType $type, ?string $groupe, bool $proposee): Taille {
            return (new Taille())
                ->setLibelle($libelle)
                ->setType($type)
                ->setGroupe($groupe)
                ->setProposeeAuxLicencies($proposee);
        };

        $repository = $this->createStub(TailleRepository::class);
        $repository->method('findAllOrdered')->willReturn([
            $ligne('S', TailleType::VETEMENT, 'Tailles adultes', true),
            $ligne('M', TailleType::VETEMENT, 'Tailles adultes', true),
            $ligne('XL', TailleType::VETEMENT, 'Tailles adultes', true),
            $ligne('10 ans', TailleType::VETEMENT, 'Tailles enfants', true),
            $ligne('128', TailleType::VETEMENT, 'Tailles enfants (fournisseur)', false),
            $ligne('L enfant', TailleType::VETEMENT, 'Tailles enfants (fournisseur)', false),
            $ligne('40', TailleType::POINTURE, null, true),
            $ligne('41', TailleType::POINTURE, null, true),
        ]);

        return new TailleReferentiel($repository);
    }
}
