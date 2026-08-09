<?php declare(strict_types=1);

namespace App\Tests\Service\Form;

use App\Service\Dotation\DotationResolver;
use App\Entity\Licencie;
use App\Enum\DotationEligibilite;
use App\Enum\NatureLicence;
use App\Enum\StockItemVetementType;
use App\Service\Dotation\DotationChoixRequestFactory;
use App\Tests\Service\Stock\StockIntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Validation serveur de la partie « dotation » du formulaire public.
 *
 * Le client Alpine valide déjà la saisie, mais rien n'empêche de poster directement :
 * ces tests couvrent ce que le serveur doit refuser, y compris un identifiant d'article
 * qui n'appartient pas au groupe proposé (faille présente avant ce chantier).
 */
final class DotationChoixRequestFactoryTest extends StockIntegrationTestCase
{
    private function factory(): DotationChoixRequestFactory
    {
        return $this->service(DotationChoixRequestFactory::class);
    }

    /** @param array<string, mixed> $params */
    private function request(array $params): Request
    {
        return new Request([], $params);
    }

    /**
     * Kit « Dotation » : veste ouverte à tous, t-shirt réservé aux renouvellements et floqué
     * (15 caractères). Plus un article hors kit, pour tester un identifiant étranger.
     *
     * @return array{0: Licencie, 1: \App\Entity\StockItem, 2: \App\Entity\StockItem, 3: \App\Entity\StockItem}
     */
    private function contexte(?NatureLicence $nature = NatureLicence::RENOUVELLEMENT): array
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $veste = $this->makeItem('Veste', StockItemVetementType::HAUT);
        $tshirt = $this->makeItem('T-shirt', StockItemVetementType::HAUT);
        $etrange = $this->makeItem('Article hors kit', StockItemVetementType::HAUT);

        $modele = $this->makeModele($season, 'Dotation 2026');
        $this->addLigne($modele, $veste, 1, 'Dotation', DotationEligibilite::TOUS);
        $this->addLigne($modele, $tshirt, 1, 'Dotation', DotationEligibilite::RENOUVELLEMENTS, true, 15);
        $this->affecterCategorie($season, $modele, $cat);

        $licencie = $this->makeLicencie($season, $cat, null, 'L', nature: $nature);

        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        return [$licencie, $veste, $tshirt, $etrange];
    }

    public function testChoixValideProduitLeDto(): void
    {
        [$licencie, $veste] = $this->contexte();

        $data = $this->factory()->fromRequest($this->request([
            'dotation_choix' => ['Dotation' => (string) $veste->getId()],
        ]), $licencie);

        self::assertNotNull($data);
        self::assertSame(['Dotation' => $veste->getId()], $data->choix);
        self::assertSame([], $data->personnalisation, 'La veste ne réclame aucun texte.');
    }

    public function testChoixManquantEstRejete(): void
    {
        [$licencie] = $this->contexte();

        self::assertNull($this->factory()->fromRequest($this->request([]), $licencie));
    }

    public function testArticleHorsDuGroupeEstRejete(): void
    {
        [$licencie, , , $etrange] = $this->contexte();

        self::assertNull(
            $this->factory()->fromRequest($this->request([
                'dotation_choix' => ['Dotation' => (string) $etrange->getId()],
            ]), $licencie),
            'Un identifiant arbitraire ne doit plus être accepté ni stocké.',
        );
    }

    public function testOptionNonEligiblePosteeEstIgnoree(): void
    {
        // Nouveau licencié : le t-shirt lui est fermé, la question ne lui est pas posée.
        // Poster son identifiant à la main ne doit rien lui donner — mais ne doit pas non
        // plus faire perdre l'inscription : la valeur est simplement ignorée.
        [$licencie, $veste, $tshirt] = $this->contexte(NatureLicence::NOUVELLE_DEMANDE);

        $data = $this->factory()->fromRequest($this->request([
            'dotation_choix' => ['Dotation' => (string) $tshirt->getId()],
            'dotation_personnalisation' => ['Dotation' => 'Coco'],
            'flocage_confirme' => '1',
        ]), $licencie);

        self::assertNotNull($data);
        self::assertSame([], $data->choix, 'Le choix non éligible n\'est pas enregistré.');
        self::assertSame([], $data->personnalisation, 'Aucun flocage n\'est dû à ce licencié.');

        // Et la dotation réellement due reste la veste.
        $lignes = $this->service(\App\Service\Dotation\DotationResolver::class)->resolveDotation($licencie);
        self::assertSame($veste->getId(), $lignes[0]['stockItem']->getId());
    }

    public function testNouveauLicencieSansChoixEstAccepte(): void
    {
        // Une seule option éligible → aucune question posée → rien à poster.
        [$licencie] = $this->contexte(NatureLicence::NOUVELLE_DEMANDE);

        $data = $this->factory()->fromRequest($this->request([]), $licencie);

        self::assertNotNull($data);
        self::assertSame([], $data->choix);
        self::assertSame([], $data->personnalisation);
    }

    public function testTexteObligatoireQuandLOptionLExige(): void
    {
        [$licencie, , $tshirt] = $this->contexte();

        self::assertNull($this->factory()->fromRequest($this->request([
            'dotation_choix' => ['Dotation' => (string) $tshirt->getId()],
            'flocage_confirme' => '1',
        ]), $licencie));
    }

    public function testTexteValideEstNormaliseEtConserve(): void
    {
        [$licencie, , $tshirt] = $this->contexte();

        $data = $this->factory()->fromRequest($this->request([
            'dotation_choix' => ['Dotation' => (string) $tshirt->getId()],
            'dotation_personnalisation' => ['Dotation' => '  Coco   Bel '],
            'flocage_confirme' => '1',
        ]), $licencie);

        self::assertNotNull($data);
        self::assertSame(['Dotation' => 'Coco Bel'], $data->personnalisation);
    }

    public function testTexteTropLongEstRejete(): void
    {
        [$licencie, , $tshirt] = $this->contexte();

        self::assertNull($this->factory()->fromRequest($this->request([
            'dotation_choix' => ['Dotation' => (string) $tshirt->getId()],
            'dotation_personnalisation' => ['Dotation' => str_repeat('A', 16)], // max 15
            'flocage_confirme' => '1',
        ]), $licencie));
    }

    public function testCaracteresNonFlocablesRejetes(): void
    {
        [$licencie, , $tshirt] = $this->contexte();

        foreach (['<script>', 'A@B', 'Coco#1', 'Emoji 🎉'] as $texte) {
            self::assertNull(
                $this->factory()->fromRequest($this->request([
                    'dotation_choix' => ['Dotation' => (string) $tshirt->getId()],
                    'dotation_personnalisation' => ['Dotation' => $texte],
                    'flocage_confirme' => '1',
                ]), $licencie),
                sprintf('« %s » ne doit pas partir en flocage.', $texte),
            );
        }
    }

    public function testAccentsApostrophesEtTiretsAcceptes(): void
    {
        [$licencie, , $tshirt] = $this->contexte();

        $data = $this->factory()->fromRequest($this->request([
            'dotation_choix' => ['Dotation' => (string) $tshirt->getId()],
            'dotation_personnalisation' => ['Dotation' => "Jean-Léo D'A"],
            'flocage_confirme' => '1',
        ]), $licencie);

        self::assertNotNull($data);
        self::assertSame("Jean-Léo D'A", $data->personnalisation['Dotation']);
    }

    public function testConfirmationDOrthographeObligatoire(): void
    {
        [$licencie, , $tshirt] = $this->contexte();

        self::assertNull(
            $this->factory()->fromRequest($this->request([
                'dotation_choix' => ['Dotation' => (string) $tshirt->getId()],
                'dotation_personnalisation' => ['Dotation' => 'Coco'],
            ]), $licencie),
            'Sans contrôle serveur, la case de confirmation ne serait qu\'un ornement.',
        );
    }

    public function testSansKitLeDtoEstVideMaisValide(): void
    {
        $season = $this->makeSeason();
        $cat = $this->makeCategory('SENIOR');
        $licencie = $this->makeLicencie($season, $cat, null, 'L');
        /** @var Licencie $licencie */
        $licencie = $this->reload($licencie);

        $data = $this->factory()->fromRequest($this->request([]), $licencie);

        self::assertNotNull($data);
        self::assertSame([], $data->choix);
    }
}
