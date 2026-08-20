<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\StockItem;
use App\Entity\User;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Service\Stock\StockMovementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'écran des transitions de fournisseur. Ce qu'il doit garantir tient en une phrase :
 * la règle se déclare dans le sens où la décision se prend — article principal d'abord,
 * anciens stocks en dessous — alors qu'en base elle est portée par l'article écoulé.
 */
final class EcoulementScreenTest extends WebTestCase
{
    private EntityManagerInterface $em;

    public function testLaCorrespondanceSeLitDepuisLArticlePrincipal(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $erima = $this->makeItem('Chaussettes', 'Erima', 'Noir');
        $nike = $this->makeItem('Chaussettes', 'Nike', 'Noir');
        $nike->setRemplaceArticle($erima);
        $this->em->flush();

        $this->stock($nike, 10, 'L');

        $crawler = $client->request('GET', '/admin/stock/ecoulement');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['Chaussettes · Erima · Noir'],
            $crawler->filter('.ecl-principal-nom')->each(static fn ($n): string => trim($n->text())),
            'C\'est l\'article commandé qui mène, pas celui qui porte la règle en base.',
        );
        self::assertSame('Chaussettes · Nike · Noir', trim($crawler->filter('.ecl-substitut-nom')->text()));
        self::assertStringContainsString('10 restants', $crawler->filter('.ecl-pastille')->text());
    }

    /** Un carton vide ne clôt pas la règle : elle reste, et l'écran dit qu'elle ne sert plus. */
    public function testUnAncienStockEpuiseEstSignaleSansDisparaitre(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $erima = $this->makeItem('Chaussettes', 'Erima', 'Noir');
        $nike = $this->makeItem('Chaussettes', 'Nike', 'Noir');
        $nike->setRemplaceArticle($erima);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/stock/ecoulement');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.ecl-substitut'));
        self::assertSame('Épuisé', trim($crawler->filter('.ecl-pastille-epuise')->text()));
    }

    /**
     * Le geste de l'écran — « je commande ça, j'ai ça à écouler » — doit écrire la relation
     * dans l'autre sens : c'est l'ancien stock qui reçoit la règle.
     */
    public function testCreerUneCorrespondanceEcritLaRegleSurLAncienStock(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $erima = $this->makeItem('Chaussettes', 'Erima', 'Noir');
        $nike = $this->makeItem('Chaussettes', 'Nike', 'Noir');
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/stock/ecoulement');
        $token = $crawler->filter('form[action$="/ecoulement/lier"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/stock/ecoulement/lier', [
            '_token' => $token,
            'principal' => (string) $erima->getId(),
            'a_ecouler' => (string) $nike->getId(),
        ]);

        self::assertResponseRedirects('/admin/stock/ecoulement');
        $this->em->clear();

        $relu = $this->em->getRepository(StockItem::class)->find($nike->getId());
        self::assertSame($erima->getId(), $relu->getRemplaceArticle()?->getId());
    }

    /** Deux types de vêtement différents feraient servir la taille du bas sur un haut. */
    public function testUnTypeDeVetementDifferentEstRefuse(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $maillot = $this->makeItem('Maillot', 'Erima', 'Rouge', StockItemVetementType::HAUT);
        $short = $this->makeItem('Short', 'Nike', 'Noir', StockItemVetementType::BAS);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/stock/ecoulement');
        $token = $crawler->filter('form[action$="/ecoulement/lier"] input[name="_token"]')->attr('value');

        $client->request('POST', '/admin/stock/ecoulement/lier', [
            '_token' => $token,
            'principal' => (string) $maillot->getId(),
            'a_ecouler' => (string) $short->getId(),
        ]);

        $client->followRedirect();
        self::assertStringContainsString('ne se mesure pas comme cet article', $client->getResponse()->getContent());

        $this->em->clear();
        self::assertNull($this->em->getRepository(StockItem::class)->find($short->getId())->getRemplaceArticle());
    }

    /**
     * Corriger une règle posée à l'envers — le cas de la prod. Sans cette action, il fallait
     * la retirer puis la recréer, et « Retirer » se lit comme un abandon quand on veut
     * seulement retourner le sens.
     */
    public function testInverserRetourneLeSensDeLaCorrespondance(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $erima = $this->makeItem('Chaussettes', 'Erima', 'Noir');
        $nike = $this->makeItem('Chaussettes', 'Nike', 'Noir');
        // Déclarée à l'envers : c'est l'ERIMA qu'on commande, pas le Nike.
        $erima->setRemplaceArticle($nike);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/stock/ecoulement');
        $form = $crawler->filter('form[action$="/inverser"]');

        $client->request('POST', $form->attr('action'), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
        ]);

        self::assertResponseRedirects('/admin/stock/ecoulement');
        $this->em->clear();

        $repo = $this->em->getRepository(StockItem::class);
        self::assertNull($repo->find($erima->getId())->getRemplaceArticle(), 'L\'ERIMA ne s\'écoule plus.');
        self::assertSame(
            $erima->getId(),
            $repo->find($nike->getId())->getRemplaceArticle()?->getId(),
            'C\'est le Nike qui s\'écoule maintenant, à la place de l\'ERIMA.',
        );
    }

    /** Au-delà d'un ancien stock, « inverser » ne désigne rien : le bouton n'apparaît pas. */
    public function testInverserNEstPasProposeAPlusieursAnciensStocks(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $erima = $this->makeItem('Chaussettes', 'Erima', 'Noir');
        $this->makeItem('Chaussettes', 'Nike', 'Noir')->setRemplaceArticle($erima);
        $this->makeItem('Chaussettes', 'Kappa', 'Rouge')->setRemplaceArticle($erima);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/stock/ecoulement');

        self::assertCount(2, $crawler->filter('.ecl-substitut'));
        self::assertCount(0, $crawler->filter('form[action$="/inverser"]'));
    }

    public function testRetirerRendLArticleAuxCommandes(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $erima = $this->makeItem('Chaussettes', 'Erima', 'Noir');
        $nike = $this->makeItem('Chaussettes', 'Nike', 'Noir');
        $nike->setRemplaceArticle($erima);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/stock/ecoulement');
        $form = $crawler->filter('form[action$="/delier"]');
        $token = $form->filter('input[name="_token"]')->attr('value');

        $client->request('POST', $form->attr('action'), ['_token' => $token]);

        self::assertResponseRedirects('/admin/stock/ecoulement');
        $this->em->clear();
        self::assertNull($this->em->getRepository(StockItem::class)->find($nike->getId())->getRemplaceArticle());
    }

    /**
     * La fiche article n'écrit plus la règle. Enregistrer un article sans champ d'écoulement
     * effacerait sinon la correspondance à chaque changement de couleur.
     */
    public function testEnregistrerUnArticleNEffacePasSaCorrespondance(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $erima = $this->makeItem('Chaussettes', 'Erima', 'Noir');
        $nike = $this->makeItem('Chaussettes', 'Nike', 'Noir');
        $nike->setRemplaceArticle($erima);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/stock/items/' . $nike->getId() . '/modifier');
        self::assertResponseIsSuccessful();

        // Les champs conditionnels vivent dans des <template x-if> qu'Alpine monte côté
        // client : le crawler ne les voit pas. On rejoue donc la soumission telle que le
        // navigateur l'envoie.
        $form = $crawler->filter('form.stock-items-form')->form();
        $client->request('POST', '/admin/stock/items/' . $nike->getId() . '/modifier', [
            'stock_item' => [
                'nom' => 'Chaussettes',
                '_token' => $form['stock_item[_token]']->getValue(),
            ],
            'kind' => StockItemKind::EQUIPEMENT->value,
            'marque' => 'Nike',
            'couleur' => 'Blanc',
            'typeVetement' => StockItemVetementType::CHAUSSURES->value,
        ]);

        $this->em->clear();
        $relu = $this->em->getRepository(StockItem::class)->find($nike->getId());

        self::assertSame('Blanc', $relu->getCouleur());
        self::assertSame($erima->getId(), $relu->getRemplaceArticle()?->getId(), 'La règle survit à l\'enregistrement.');
    }

    /**
     * Les deux côtés d'une correspondance doivent porter le même type de vêtement : c'est lui
     * qui dit quel champ du dossier lire. Le laisser changer passerait inaperçu — la fiche
     * n'affiche plus la règle qu'en lecture — et la dotation servirait la taille du bas sur
     * un haut.
     */
    public function testChangerLeTypeDeVetementDUnArticleEngageEstRefuse(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);

        $erima = $this->makeItem('Chaussettes', 'Erima', 'Noir');
        $nike = $this->makeItem('Chaussettes', 'Nike', 'Noir');
        $nike->setRemplaceArticle($erima);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/stock/items/' . $nike->getId() . '/modifier');
        $form = $crawler->filter('form.stock-items-form')->form();

        $client->request('POST', '/admin/stock/items/' . $nike->getId() . '/modifier', [
            'stock_item' => ['nom' => 'Chaussettes', '_token' => $form['stock_item[_token]']->getValue()],
            'kind' => StockItemKind::EQUIPEMENT->value,
            'marque' => 'Nike',
            'couleur' => 'Noir',
            'typeVetement' => StockItemVetementType::HAUT->value,
        ]);

        // Sans apostrophe ni chevrons : le message en contient, et le HTML les échappe.
        self::assertStringContainsString('avant de changer son type de vêtement', $client->getResponse()->getContent());

        $this->em->clear();
        $relu = $this->em->getRepository(StockItem::class)->find($nike->getId());
        self::assertSame(StockItemVetementType::CHAUSSURES, $relu->getTypeVetement());
        self::assertSame($erima->getId(), $relu->getRemplaceArticle()?->getId());
    }

    private function makeItem(
        string $nom,
        string $marque,
        string $couleur,
        StockItemVetementType $type = StockItemVetementType::CHAUSSURES,
    ): StockItem {
        $item = (new StockItem())
            ->setNom($nom)->setMarque($marque)->setCouleur($couleur)
            ->setKind(StockItemKind::EQUIPEMENT)->setTypeVetement($type);
        $this->em->persist($item);

        return $item;
    }

    private function stock(StockItem $item, int $quantite, string $taille): void
    {
        self::getContainer()->get(StockMovementService::class)->recordMovement(
            $item, $quantite, StockMovementType::ENTREE, StockMovementSource::MANUEL, null, null, taille: $taille,
        );
    }

    private function loginAdmin(KernelBrowser $client): void
    {
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $user = (new User())->setEmail('admin-ecoulement@example.com')->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        $client->loginUser($user);
    }
}
