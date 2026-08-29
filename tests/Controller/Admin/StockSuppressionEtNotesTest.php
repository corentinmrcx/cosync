<?php declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Entity\Season;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;
use App\Repository\StockTailleNoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Retirer un article du catalogue, et les remarques portées sur son stock.
 *
 * L'archivage protège une histoire réelle — une dotation remise, une commande reçue. Une
 * erreur de saisie n'en est pas une : l'article et ses mouvements manuels s'effacent.
 */
final class StockSuppressionEtNotesTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Season $season;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->season = (new Season())->setLabel('2025-2026')->setCotisationDefaut(85);
        $this->em->persist($this->season);
        $this->em->flush();
    }

    public function testUnArticleJamaisMouvementeEstSupprimeSansCaseACocher(): void
    {
        $client = $this->loginAdmin();
        $item = $this->makeItem('Maillto');
        $id = $item->getId();

        $crawler = $client->request('GET', '/admin/stock/items/' . $id . '/supprimer');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Suppression définitive', $crawler->html());
        self::assertCount(0, $crawler->filter('input[name="confirmation"]'), 'Rien à perdre : rien à confirmer deux fois.');

        $client->submitForm('Supprimer définitivement');

        self::assertResponseRedirects('/admin/stock/gestion');
        self::assertNull($this->itemRepository()->find($id));
    }

    /**
     * Le cas de l'erreur de saisie : l'article a été manipulé à la main, tout est ressorti,
     * il ne reste rien. Il part avec ses mouvements — mais pas sans un second geste.
     */
    public function testUnArticleSoldeManipuleALaMainPartAvecSesMouvementsApresConfirmation(): void
    {
        $client = $this->loginAdmin();
        $item = $this->makeItem('Chasuble bleue');
        $this->mouvement($item, 3, StockMovementType::ENTREE, StockMovementSource::MANUEL, 'L');
        $this->mouvement($item, 3, StockMovementType::SORTIE, StockMovementSource::MANUEL, 'L');
        $id = $item->getId();

        $crawler = $client->request('GET', '/admin/stock/items/' . $id . '/supprimer');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('2 mouvements', $crawler->html());
        self::assertCount(1, $crawler->filter('input[name="confirmation"]'));

        // Sans la case, l'écran renvoie sur lui-même et l'article reste en place.
        $client->request('POST', '/admin/stock/items/' . $id . '/supprimer', [
            '_token' => $this->jetonSuppression($client, $id),
        ]);
        self::assertResponseRedirects('/admin/stock/items/' . $id . '/supprimer');
        self::assertNotNull($this->itemRepository()->find($id));

        $client->request('POST', '/admin/stock/items/' . $id . '/supprimer', [
            '_token' => $this->jetonSuppression($client, $id),
            'confirmation' => '1',
        ]);

        self::assertResponseRedirects('/admin/stock/gestion');
        self::assertNull($this->itemRepository()->find($id));
        self::assertSame(0, $this->movementRepository()->count([]), 'Les mouvements partent avec leur article.');
    }

    public function testUnArticleQuiPorteEncoreDuStockNePeutQueSArchiver(): void
    {
        $client = $this->loginAdmin();
        $item = $this->makeItem('Ballon T5');
        $this->mouvement($item, 12, StockMovementType::ENTREE, StockMovementSource::MANUEL, null);
        $id = $item->getId();

        $crawler = $client->request('GET', '/admin/stock/items/' . $id . '/supprimer');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('il reste 12 unités en stock', $crawler->html());

        $client->submitForm('Archiver l\'article');

        self::assertResponseRedirects('/admin/stock/gestion');
        $this->em->clear();
        $archive = $this->itemRepository()->find($id);
        self::assertNotNull($archive);
        self::assertFalse($archive->isActif());
    }

    /**
     * Supprimable ne veut pas dire obligé de supprimer : l'admin peut vouloir garder
     * l'article sous le coude, hors des listes.
     */
    public function testUnArticleSupprimablePeutEtreArchivePlutot(): void
    {
        $client = $this->loginAdmin();
        $item = $this->makeItem('Chasuble jaune');
        $this->mouvement($item, 2, StockMovementType::ENTREE, StockMovementSource::MANUEL, null);
        $this->mouvement($item, 2, StockMovementType::SORTIE, StockMovementSource::MANUEL, null);
        $id = $item->getId();

        $crawler = $client->request('GET', '/admin/stock/items/' . $id . '/supprimer');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Archiver plutôt', $crawler->filter('.stock-items-actions')->text());

        $client->submitForm('Archiver plutôt');

        self::assertResponseRedirects('/admin/stock/gestion');
        $this->em->clear();
        self::assertFalse($this->itemRepository()->find($id)?->isActif(), 'L\'article est rangé, pas effacé.');
        self::assertSame(2, $this->movementRepository()->count([]), 'Ses mouvements sont intacts.');
    }

    /**
     * Un article déjà archivé garde son bouton Supprimer — il a pu l'être par erreur. Mais
     * s'il porte encore du stock, l'écran ne propose plus rien à valider : l'archiver une
     * seconde fois ne ferait rien.
     */
    public function testUnArticleDejaArchiveEtNonSupprimableNOffreAucuneAction(): void
    {
        $client = $this->loginAdmin();
        $item = $this->makeItem('Ballon T4');
        $item->setActif(false);
        $this->em->flush();
        $this->mouvement($item, 3, StockMovementType::ENTREE, StockMovementSource::MANUEL, null);

        $lignes = $client->request('GET', '/admin/stock/gestion?archivés=1');
        self::assertCount(
            1,
            $lignes->filter('a[href="/admin/stock/items/' . $item->getId() . '/supprimer"]'),
            'La ligne archivée porte bien un bouton Supprimer.',
        );

        $crawler = $client->request('GET', '/admin/stock/items/' . $item->getId() . '/supprimer');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Il est déjà', $crawler->html());
        self::assertCount(0, $crawler->filter('.stock-items-actions button'));
        self::assertStringContainsString('Retour à la gestion', $crawler->filter('.stock-items-actions')->text());
    }

    /**
     * Un maillot sorti pour un joueur laisse une trace qui n'est pas une erreur de saisie :
     * même soldé, l'article s'archive. Sinon la question « qui a reçu quoi » perd sa réponse.
     */
    public function testUnArticleTouchePlarUneDotationSArchiveMemeSolde(): void
    {
        $client = $this->loginAdmin();
        $item = $this->makeItem('Maillot domicile');
        $this->mouvement($item, 1, StockMovementType::ENTREE, StockMovementSource::MANUEL, 'M');
        $this->mouvement($item, 1, StockMovementType::SORTIE, StockMovementSource::DOTATION, 'M');
        $id = $item->getId();

        $crawler = $client->request('GET', '/admin/stock/items/' . $id . '/supprimer');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Cet article ne peut pas être supprimé', $crawler->html());
        self::assertStringContainsString('dotations', $crawler->html());

        $client->submitForm('Archiver l\'article');

        $this->em->clear();
        self::assertFalse($this->itemRepository()->find($id)?->isActif());
        self::assertSame(2, $this->movementRepository()->count([]), 'L\'archivage ne touche pas à l\'historique.');
    }

    public function testUnArticleInscritDansUnKitDeDotationSArchive(): void
    {
        $client = $this->loginAdmin();
        $item = $this->makeItem('Short');
        $this->ligneDeKit($item);
        $id = $item->getId();

        $crawler = $client->request('GET', '/admin/stock/items/' . $id . '/supprimer');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('il figure dans un kit de dotation', $crawler->html());

        $client->submitForm('Archiver l\'article');

        $this->em->clear();
        self::assertFalse($this->itemRepository()->find($id)?->isActif());
    }

    /** Les déclinaisons enfant du fournisseur sont proposées à l'entrée en stock. */
    public function testUnHautSeReassortitEnTaillesEnfantDuFournisseur(): void
    {
        $client = $this->loginAdmin();
        $maillot = $this->makeItem('Maillot');
        $maillot->setKind(StockItemKind::EQUIPEMENT)->setTypeVetement(StockItemVetementType::HAUT);
        $this->em->flush();

        $crawler = $client->request('GET', '/admin/stock/gestion');
        $options = json_decode((string) $crawler->filter('#tailles-' . $maillot->getId())->attr('value'), true)['options'];

        self::assertContains('128', $options);
        self::assertContains('L enfant', $options);

        $client->request('POST', '/admin/stock/items/' . $maillot->getId() . '/mouvement', [
            '_token' => $this->jetonMouvement($client, $maillot->getId()),
            'action' => 'entree',
            'quantite' => '6',
            'taille' => '128',
        ]);

        self::assertResponseRedirects('/admin/stock/gestion');
        self::assertSame(6, $this->movementRepository()->getCurrentStockByTaille($maillot, '128'));
    }

    public function testLaVentilationParTailleSuitLOrdreDuReferentiel(): void
    {
        $client = $this->loginAdmin();
        $maillot = $this->makeItem('Maillot');
        $maillot->setKind(StockItemKind::EQUIPEMENT)->setTypeVetement(StockItemVetementType::HAUT);
        $this->em->flush();

        foreach (['128', 'M', '104', 'XL'] as $taille) {
            $this->mouvement($maillot, 1, StockMovementType::ENTREE, StockMovementSource::MANUEL, $taille);
        }

        $crawler = $client->request('GET', '/admin/stock/gestion');
        $tailles = $crawler->filter('.stock-taille-table tbody tr td:first-child')->each(static fn ($n) => trim($n->text()));

        self::assertSame(['M', 'XL', '104', '128'], $tailles, 'Un tri alphabétique rangerait 104 avant M.');
    }

    public function testUneNoteSePoseSurUneTailleEtSAfficheDansSonDetail(): void
    {
        $client = $this->loginAdmin();
        $maillot = $this->makeItem('Maillot');
        $maillot->setKind(StockItemKind::EQUIPEMENT)->setTypeVetement(StockItemVetementType::HAUT);
        $this->em->flush();
        $this->mouvement($maillot, 4, StockMovementType::ENTREE, StockMovementSource::MANUEL, '128');

        $client->request('POST', '/admin/stock/items/' . $maillot->getId() . '/note-taille', [
            '_token' => $this->jetonNote($client, $maillot->getId(), 'note-taille'),
            'taille' => '128',
            'note' => 'Taille petit, prévoir au-dessus',
        ]);

        self::assertResponseRedirects('/admin/stock/gestion');
        self::assertSame('Taille petit, prévoir au-dessus', $this->noteAffichee($client, $maillot->getId(), 'note-taille'));

        // Note vidée : la ligne disparaît plutôt que de rester vide.
        $client->request('POST', '/admin/stock/items/' . $maillot->getId() . '/note-taille', [
            '_token' => $this->jetonNote($client, $maillot->getId(), 'note-taille'),
            'taille' => '128',
            'note' => '   ',
        ]);

        self::assertSame(0, $this->noteRepository()->count([]));
    }

    /** Même garde que les mouvements : une soumission forgée n'invente pas de déclinaison. */
    public function testUneNoteSurUneTailleEtrangereALArticleEstRefusee(): void
    {
        $client = $this->loginAdmin();
        $chaussettes = $this->makeItem('Chaussettes');
        $chaussettes->setKind(StockItemKind::EQUIPEMENT)->setTypeVetement(StockItemVetementType::CHAUSSURES);
        $this->em->flush();
        $this->mouvement($chaussettes, 5, StockMovementType::ENTREE, StockMovementSource::MANUEL, '40');

        $client->request('POST', '/admin/stock/items/' . $chaussettes->getId() . '/note-taille', [
            '_token' => $this->jetonNote($client, $chaussettes->getId(), 'note-taille'),
            'taille' => 'XL',
            'note' => 'Rangées au fond',
        ]);

        self::assertResponseRedirects('/admin/stock/gestion');
        self::assertSame(0, $this->noteRepository()->count([]));
        self::assertStringContainsString('ne correspond pas', $client->followRedirect()->html());
    }

    /**
     * La note de l'article s'écrit depuis la modale du tableau — et se relit dans la même,
     * pas dans la cellule : trois lignes de texte y déformaient la ligne de l'article.
     */
    public function testLaNoteDeLArticleSEcritEtSeRelitDepuisLaModale(): void
    {
        $client = $this->loginAdmin();
        $item = $this->makeItem('Gourde');

        $client->request('POST', '/admin/stock/items/' . $item->getId() . '/note', [
            '_token' => $this->jetonNote($client, $item->getId(), 'note'),
            'note' => 'Armoire du local, étagère du haut',
        ]);

        self::assertResponseRedirects('/admin/stock/gestion');
        self::assertSame('Armoire du local, étagère du haut', $this->noteAffichee($client, $item->getId(), 'note'));

        $crawler = $client->request('GET', '/admin/stock/gestion');
        self::assertStringNotContainsString(
            'Armoire du local, étagère du haut',
            $crawler->filter('.stock-item-meta')->text(''),
            'La cellule ne recopie pas la note : elle n\'affiche qu\'un bouton.',
        );
        self::assertStringContainsString('Voir la note', $crawler->filter('.stock-note-btn')->first()->text());
    }

    /** Le texte de la note tel que la modale l'affichera pour cet article. */
    private function noteAffichee(KernelBrowser $client, int $id, string $route): ?string
    {
        $client->request('GET', '/admin/stock/gestion');
        $chemin = '/admin/stock/items/' . $id . '/' . $route;

        return $this->chargeUtileNote((string) $client->getResponse()->getContent(), $chemin)['note'] ?? null;
    }

    /**
     * Charge utile passée à la modale de note par le bouton visant ce chemin.
     *
     * Lue dans le HTML brut, pas via le Crawler : le parseur DOM de PHP écarte les
     * attributs dont le nom n'est pas un nom XML valide, et `@click` en fait partie.
     *
     * @return array<string, mixed>|null
     */
    private function chargeUtileNote(string $html, string $chemin): ?array
    {
        preg_match_all('/@click(?:\.stop)?="ouvrir\((.*?)\)"/s', $html, $trouvees);

        foreach ($trouvees[1] as $brut) {
            $charge = json_decode(html_entity_decode($brut, \ENT_QUOTES | \ENT_HTML5), true);

            if (is_array($charge) && ($charge['action'] ?? null) === $chemin) {
                return $charge;
            }
        }

        return null;
    }

    private function jetonSuppression(KernelBrowser $client, int $id): string
    {
        $crawler = $client->request('GET', '/admin/stock/items/' . $id . '/supprimer');

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    private function jetonMouvement(KernelBrowser $client, int $id): string
    {
        $crawler = $client->request('GET', '/admin/stock/gestion');

        return (string) $crawler->filter('#csrf-' . $id)->attr('value');
    }

    /**
     * Jeton lu dans la charge utile que la ligne passe à la modale de note — c'est
     * exactement ce que l'écran envoie.
     */
    private function jetonNote(KernelBrowser $client, int $id, string $route): string
    {
        $client->request('GET', '/admin/stock/gestion');
        $chemin = '/admin/stock/items/' . $id . '/' . $route;

        $charge = $this->chargeUtileNote((string) $client->getResponse()->getContent(), $chemin);
        self::assertNotNull($charge, 'Aucun bouton de note ne pointe vers ' . $chemin . '.');

        return (string) $charge['token'];
    }

    private function itemRepository(): StockItemRepository
    {
        return self::getContainer()->get(StockItemRepository::class);
    }

    private function movementRepository(): StockMovementRepository
    {
        return self::getContainer()->get(StockMovementRepository::class);
    }

    private function noteRepository(): StockTailleNoteRepository
    {
        return self::getContainer()->get(StockTailleNoteRepository::class);
    }

    private function makeItem(string $nom): StockItem
    {
        $item = (new StockItem())->setNom($nom);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    private function mouvement(
        StockItem $item,
        int $quantite,
        StockMovementType $type,
        StockMovementSource $source,
        ?string $taille,
    ): void {
        $mouvement = (new StockMovement())
            ->setItem($item)
            ->setQuantite($quantite)
            ->setType($type)
            ->setSource($source)
            ->setTaille($taille);

        $this->em->persist($mouvement);
        $this->em->flush();
    }

    private function ligneDeKit(StockItem $item): void
    {
        $modele = (new DotationModele())->setSeason($this->season)->setNom('Kit U15');
        $this->em->persist($modele);

        $ligne = (new DotationModeleLigne())->setModele($modele)->setStockItem($item);
        $this->em->persist($ligne);
        $this->em->flush();
    }

    private function loginAdmin(): KernelBrowser
    {
        $user = (new User())->setSuperAdmin(true)->setEmail('admin@example.test')->setRoles(['ROLE_ADMIN']);
        $user->setPassword('x');
        $user->setSelectedSeason($this->season);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        return $this->client;
    }
}
