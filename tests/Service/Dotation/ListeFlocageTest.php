<?php declare(strict_types=1);

namespace App\Tests\Service\Dotation;

use App\Entity\Category;
use App\Entity\DossierClub;
use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\Team;
use App\Enum\LicenceStatus;
use App\Enum\StockItemVetementType;
use App\Service\Dotation\ListeFlocageCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * La feuille remise au floqueur.
 *
 * Il ne connaît personne au club : il lui faut l'article, sa référence, la taille et le texte.
 * Le nom du porteur et son équipe restent à l'écran — c'est là que le club se relit — et ne
 * franchissent pas la porte (§6). C'est ce que ce test tient.
 */
final class ListeFlocageTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Season $season;

    public function testLaFeuilleNePorteNiNomNiEquipe(): void
    {
        $this->demarrer();

        $seniors = (new Team())->setName('Séniors')->setSeason($this->season);
        $this->em->persist($seniors);

        $maillot = $this->makeItem('Maillot', 'Erima', 'Jaune', '1082334');
        $this->makeBesoin($maillot, 'L', 'Robert')->setLicencie($this->makeLicencie($seniors));
        $this->em->flush();

        $html = $this->rendre();

        self::assertStringContainsString('Maillot · Erima · Jaune', $html);
        self::assertStringContainsString('réf. 1082334', $html);
        self::assertStringContainsString('ROBERT', $html, 'Le texte à floquer, lui, est bien là.');

        self::assertStringNotContainsString('DUPONT', $html, 'Le porteur ne sort pas du club.');
        self::assertStringNotContainsString('Séniors', $html, 'L\'équipe non plus : le floqueur n\'en fait rien.');
    }

    /**
     * Le texte part en capitales sur le vêtement : la feuille le porte tel quel, accents
     * compris. Un `text-transform` CSS l'aurait laissé en minuscules dans le document, et le
     * floqueur aurait eu à deviner laquelle des deux casses fait foi.
     */
    public function testLeTexteAFloquerSortEnCapitalesAccentsCompris(): void
    {
        $this->demarrer();

        $maillot = $this->makeItem('Maillot', 'Erima', 'Jaune', null);
        $this->makeBesoin($maillot, 'L', 'Noël Lefèvre')->setLicencie($this->makeLicencie(null));
        $this->em->flush();

        $html = $this->rendre();

        self::assertStringContainsString('NOËL LEFÈVRE', $html);
    }

    /**
     * Deux pièces identiques — même article, même taille, même texte — sont un seul geste
     * répété. Deux lignes à l'identique se relisent comme deux consignes différentes.
     */
    public function testLesPiecesIdentiquesTiennentSurUneLigneAvecLeurQuantite(): void
    {
        $this->demarrer();

        $sac = $this->makeItem('Sac', 'Erima', 'Noir', null);
        $this->makeBesoin($sac, 'L', 'SOUDRON')->setLicencie($this->makeLicencie(null));
        $this->makeBesoin($sac, 'L', 'SOUDRON')->setLicencie($this->makeLicencie(null));
        $this->em->flush();

        $doc = self::getContainer()->get(ListeFlocageCollector::class)->collecter($this->season);

        self::assertCount(1, $doc->articles);
        self::assertCount(1, $doc->articles[0]->lignes, 'Une seule consigne, pas deux.');
        self::assertSame(2, $doc->articles[0]->lignes[0]->quantite);
        self::assertSame(2, $doc->pieces);
    }

    /** Le floqueur enchaîne les tailles dans l'ordre du carton, pas dans celui de l'alphabet. */
    public function testLesTaillesSuiventLOrdreDuReferentielPasLAlphabet(): void
    {
        $this->demarrer();

        $polo = $this->makeItem('Polo', 'Erima', 'Noir', null);
        foreach (['XL', 'S', 'L', 'M'] as $i => $taille) {
            $this->makeBesoin($polo, $taille, 'TEXTE' . $i)->setLicencie($this->makeLicencie(null));
        }
        $this->em->flush();

        $doc = self::getContainer()->get(ListeFlocageCollector::class)->collecter($this->season);

        self::assertSame(
            ['S', 'M', 'L', 'XL'],
            array_map(static fn ($ligne): ?string => $ligne->etiquetteTaille, $doc->articles[0]->lignes),
        );
    }

    private function rendre(): string
    {
        $doc = self::getContainer()->get(ListeFlocageCollector::class)->collecter($this->season);

        return self::getContainer()->get(Environment::class)->render('pdf/liste_flocage.html.twig', [
            'doc' => $doc,
            'logoDataUrl' => 'data:image/png;base64,iVBORw0KGgo=',
            'foyerLogoDataUrl' => 'data:image/png;base64,iVBORw0KGgo=',
        ]);
    }

    private function demarrer(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        // Les globales Twig du projet lisent la saison courante en session.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $this->em->persist($this->season);
        $this->em->flush();
    }

    private function makeItem(string $nom, string $marque, string $couleur, ?string $ref): StockItem
    {
        $item = (new StockItem())
            ->setNom($nom)->setMarque($marque)->setCouleur($couleur)
            ->setTypeVetement(StockItemVetementType::HAUT)->setRefCatalogue($ref);
        $this->em->persist($item);

        return $item;
    }

    private function makeBesoin(StockItem $item, string $taille, string $texte): DotationBesoin
    {
        $besoin = (new DotationBesoin())
            ->setSeason($this->season)->setStockItem($item)
            ->setTaille($taille)->setPersonnalisation($texte);
        $this->em->persist($besoin);

        return $besoin;
    }

    private function makeLicencie(?Team $team): Licencie
    {
        static $n = 0;
        ++$n;

        $category = (new Category())->setCode('SENIOR' . $n)->setLabel('Séniors')->setIsEcoleFoot(false);
        $this->em->persist($category);

        $licencie = (new Licencie())
            ->setNom('DUPONT')->setPrenom('Thomas')
            ->setDateNaissance(new \DateTimeImmutable('2000-01-01'))
            ->setCategory($category)->setSeason($this->season);
        if ($team !== null) {
            $licencie->setTeam($team);
        }
        $this->em->persist($licencie);

        $dossier = (new DossierClub())->setLicencie($licencie);
        $dossier->setTailleHaut('L')->setStatus(LicenceStatus::VALIDATED);
        $this->em->persist($dossier);

        return $licencie;
    }
}
