<?php declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reprise de l'inventaire papier d'août 2026 — matériel, accessoires et pharmacie.
 *
 * Les feuilles « Maillot de match » et « vetement neuf » du classeur ont déjà été saisies à la
 * main dans l'application (articles 1 à 38, mouvements du 15/08/2026) : cette reprise ne les
 * touche pas. Elle ne porte que les trois feuilles jamais saisies — MATERIEL, MATERIEL PAS NEUF
 * et PHARMACIE — en suivant la nomenclature des articles existants (nom · marque · couleur,
 * couleurs capitalisées, détails d'état dans la note de l'article).
 *
 * ⚠️ Ceci est une commande, et non une migration, délibérément. Un inventaire n'est ni du schéma
 * ni un référentiel (§13) : c'est la donnée d'un club à une date. Portée par une migration, elle
 * était rejouée sur **toute** base construite en repartant de zéro — dont la base de test de la
 * CI, qui héritait alors de 87 articles et 95 mouvements avant le premier test. Ne pas l'y
 * remettre : le stock de Soudron n'a rien à faire dans une base neuve.
 *
 * Idempotente : chaque article n'est créé que s'il n'existe pas déjà (même nom, même marque,
 * même couleur), et son entrée d'inventaire n'est posée que si l'article n'a encore aucun
 * mouvement — un article déjà géré entre-temps n'est jamais réécrit. La commande se relance donc
 * sans risque de doublon.
 *
 * Choix de reprise, quand le classeur disait plus que le modèle :
 * - les états (« neuf », « utilisé », lieu de rangement) vont dans la note de l'article ;
 * - les lignes d'un même article comptées à deux endroits sont additionnées, le détail en note
 *   (brassards capitaine, jeux de coupelles, sacs à ballons, ballons T4 Uhlsport jaunes) ;
 * - la taille des ballons fait partie du nom (« Ballon T4 »), comme sur le classeur ;
 * - les chasubles portent un type « haut » : leurs tailles XS / M / XL vivent en déclinaisons ;
 *   la chasuble Errea « taille adulte » n'a pas de déclinaison, sa note le dit ;
 * - deux articles sont créés sans stock : le ballon T4 Protouch (compté à zéro) et les
 *   pansements en vrac (« tout plein », quantité jamais comptée).
 *
 * Il n'y a pas de marche arrière : le stock est dérivé des mouvements, et des mouvements ont pu
 * s'ajouter depuis (dotations, corrections). Un article entré à tort se retire depuis l'écran de
 * gestion du stock, qui sait dire ce qui est supprimable et ce qui doit s'archiver.
 */
#[AsCommand(
    name: 'app:inventaire:aout-2026',
    description: 'Reprise de l\'inventaire d\'août 2026 : matériel, accessoires et pharmacie (les vêtements étaient déjà saisis)',
)]
final class InventaireAout2026Command extends Command
{
    private const DATE_INVENTAIRE = '2026-08-31 12:00:00';
    private const AUTEUR_EMAIL = 'corentinmarcoux51@gmail.com';

    private const MATERIEL = 'Accessoire & Matériel';
    private const PHARMACIE = 'Pharmacie';

    /**
     * [nom, marque, couleur, catégorie, typeVetement, note, mouvements [[quantité, taille]]].
     *
     * @var list<array{0: string, 1: ?string, 2: ?string, 3: string, 4: ?string, 5: ?string, 6: list<array{0: int, 1: ?string}>}>
     */
    private const ARTICLES = [
        // ── Matériel de l'armoire ──
        ['Brassard capitaine', null, null, self::MATERIEL, null, '3 neufs, 2 utilisés, 1 UEFA utilisé', [[6, null]]],
        ['Brassard capitaine', null, 'Bleu blanc rouge', self::MATERIEL, null, null, [[1, null]]],
        ['Brassard délégué', null, null, self::MATERIEL, null, null, [[1, null]]],
        ['Sac à dos 22L', 'Nike', null, self::MATERIEL, null, null, [[1, null]]],
        ['Sac à dos 30L', 'Nike', 'Noir', self::MATERIEL, null, null, [[2, null]]],
        ['Sac à ballons', null, null, self::MATERIEL, null, '2 dans l\'armoire, 8 sacs divers', [[10, null]]],
        ['Jeu de coupelles', null, null, self::MATERIEL, null, '1 neuf et 2 usagés dans l\'armoire, 1 dans la salle', [[4, null]]],
        ['Chrono', null, null, self::MATERIEL, null, 'Neufs', [[2, null]]],
        ['Sifflet', null, null, self::MATERIEL, null, 'Neufs', [[11, null]]],
        ['Aiguille à gonfler', null, null, self::MATERIEL, null, null, [[2, null]]],

        // ── Ballons ──
        ['Ballon T3', 'Nike', 'Blanc', self::MATERIEL, null, null, [[12, null]]],
        ['Ballon T3', 'Erima', 'Bleu', self::MATERIEL, null, null, [[14, null]]],
        ['Ballon T4', 'Adidas', 'Bleu et blanc', self::MATERIEL, null, null, [[30, null]]],
        ['Ballon T4', 'Uhlsport', 'Jaune', self::MATERIEL, null, 'Dont 4 neufs dans l\'armoire', [[16, null]]],
        ['Ballon T4', 'Nike', 'Blanc', self::MATERIEL, null, null, [[1, null]]],
        ['Ballon T4', 'Protouch', 'Bleu', self::MATERIEL, null, 'Compté à zéro à l\'inventaire d\'août 2026', []],
        ['Ballon T5', 'Uhlsport', 'Blanc', self::MATERIEL, null, 'Neufs, dans l\'armoire', [[5, null]]],
        ['Ballon T5', 'Uhlsport', 'Blanc et noir', self::MATERIEL, null, null, [[11, null]]],
        ['Ballon T5', 'Nike', 'Jaune', self::MATERIEL, null, null, [[3, null]]],
        ['Ballon T5', 'Nike', 'Blanc', self::MATERIEL, null, null, [[3, null]]],
        ['Ballon T5 light', 'Nike', 'Blanc', self::MATERIEL, null, null, [[4, null]]],
        ['Ballon de match T5', 'Nike', null, self::MATERIEL, null, null, [[4, null]]],
        ['Ballon futsal T4', 'Nike', 'Blanc et jaune', self::MATERIEL, null, null, [[5, null]]],
        ['Ballon futsal T4', 'Adidas', 'Jaune', self::MATERIEL, null, null, [[1, null]]],
        ['Ballon futsal T4', 'Nike', 'Blanc et bleu', self::MATERIEL, null, null, [[1, null]]],

        // ── Chasubles (tailles en déclinaisons, comme les vêtements) ──
        ['Chasuble', 'Errea', 'Orange', self::MATERIEL, 'haut', 'Taille adulte', [[9, null]]],
        ['Chasuble', 'Tremblay', 'Vert', self::MATERIEL, 'haut', null, [[17, 'XS'], [2, 'XL']]],
        ['Chasuble', 'Tremblay', 'Bleu', self::MATERIEL, 'haut', null, [[14, 'XS'], [6, 'M']]],
        ['Chasuble', 'Tremblay', 'Jaune', self::MATERIEL, 'haut', null, [[14, 'XS'], [4, 'M'], [2, 'XL']]],
        ['Chasuble', 'Tremblay', 'Orange', self::MATERIEL, 'haut', null, [[4, 'M']]],
        ['Chasuble', 'Sporti', 'Orange', self::MATERIEL, 'haut', null, [[10, 'XS'], [10, 'M'], [18, 'XL']]],
        ['Chasuble', 'Sporti', 'Vert', self::MATERIEL, 'haut', null, [[10, 'XS'], [10, 'M'], [13, 'XL']]],
        ['Chasuble', 'Sporti', 'Bleu', self::MATERIEL, 'haut', null, [[10, 'XS'], [10, 'M'], [10, 'XL']]],

        // ── Terrain ──
        ['Mini-but 90 cm', 'Sporti', 'Blanc', self::MATERIEL, null, null, [[2, null]]],
        ['Mini-but 4x1,50 m', null, 'Rouge et bleu', self::MATERIEL, null, null, [[4, null]]],
        ['Mini-but 0,95x0,70 m', 'Kipsta', null, self::MATERIEL, null, null, [[4, null]]],
        ['Drapeau de touche', 'Sporti', 'Jaune et rouge', self::MATERIEL, null, '1 jeu, sur roulement', [[1, null]]],
        ['Drapeau de touche', 'Tremblay', 'Jaune et rouge', self::MATERIEL, null, '1 jeu dépareillé, carreaux et uni', [[1, null]]],
        ['Cerceau petit', 'Tremblay', 'Jaune', self::MATERIEL, null, null, [[5, null]]],
        ['Cerceau petit', 'Tremblay', 'Vert', self::MATERIEL, null, null, [[5, null]]],
        ['Cerceau petit', 'Tremblay', 'Bleu', self::MATERIEL, null, null, [[4, null]]],
        ['Cerceau petit', 'Tremblay', 'Orange', self::MATERIEL, null, null, [[5, null]]],
        ['Cerceau grand', 'Tremblay', 'Bleu', self::MATERIEL, null, null, [[5, null]]],
        ['Cerceau grand', 'Tremblay', 'Jaune', self::MATERIEL, null, null, [[5, null]]],
        ['Cerceau grand', 'Tremblay', 'Vert', self::MATERIEL, null, null, [[5, null]]],
        ['Cerceau grand', 'Tremblay', 'Orange', self::MATERIEL, null, null, [[4, null]]],
        ['Poteau de corner', 'Tremblay', null, self::MATERIEL, null, 'Damier', [[4, null]]],
        ['Rotule poteau de corner', null, null, self::MATERIEL, null, null, [[1, null]]],
        ['Insert poteau de corner', null, null, self::MATERIEL, null, null, [[4, null]]],
        ['Insert sol poteau de corner', null, null, self::MATERIEL, null, null, [[4, null]]],
        ['Piquet de slalom', null, 'Jaune', self::MATERIEL, null, null, [[19, null]]],
        ['Piquet de slalom', null, 'Rouge', self::MATERIEL, null, null, [[4, null]]],
        ['Piquet de slalom', null, 'Bleu', self::MATERIEL, null, null, [[10, null]]],
        ['Piquet de slalom', null, 'Blanc', self::MATERIEL, null, null, [[3, null]]],
        ['Arc de précision', null, null, self::MATERIEL, null, null, [[9, null]]],
        ['Haie', null, null, self::MATERIEL, null, null, [[6, null]]],
        ['Planche de rebond', null, null, self::MATERIEL, null, null, [[2, null]]],
        ['Échelle de parcours', null, null, self::MATERIEL, null, null, [[2, null]]],
        ['Plaque de slalom', null, null, self::MATERIEL, null, null, [[1, null]]],
        ['Medicine ball', null, null, self::MATERIEL, null, null, [[2, null]]],
        ['Balle de tennis', null, null, self::MATERIEL, null, null, [[3, null]]],
        ['Mannequin', null, null, self::MATERIEL, null, null, [[1, null]]],
        ['Cône petit lourd', null, null, self::MATERIEL, null, null, [[9, null]]],

        // ── Entretien & outillage ──
        ['Pompe à ballon', null, null, self::MATERIEL, null, null, [[3, null]]],
        ['Gonfleur électrique', null, 'Jaune', self::MATERIEL, null, 'Bruyant', [[1, null]]],
        ['Pulvérisateur', null, null, self::MATERIEL, null, null, [[1, null]]],
        ['Visseuse', null, null, self::MATERIEL, null, null, [[1, null]]],
        ['Mélangeur à peinture', null, null, self::MATERIEL, null, null, [[1, null]]],
        ['Traceuse à terrain', null, null, self::MATERIEL, null, null, [[1, null]]],
        ['Chariot', null, null, self::MATERIEL, null, null, [[1, null]]],
        ['Peinture 20 L', null, 'Blanc', self::MATERIEL, null, null, [[3, null]]],
        ['Décamètre 50 m', null, null, self::MATERIEL, null, 'HS — il manque 30 cm', [[1, null]]],

        // ── Pharmacie ──
        ['Désinfectant', null, null, self::PHARMACIE, null, null, [[4, null]]],
        ['Lingettes désinfectantes', null, null, self::PHARMACIE, null, null, [[6, null]]],
        ['Sparadrap', null, null, self::PHARMACIE, null, null, [[6, null]]],
        ['Compresses 5x5', null, null, self::PHARMACIE, null, 'Comptées en boîtes', [[3, null]]],
        ['Compresses 10x10', null, null, self::PHARMACIE, null, 'Comptées en boîtes, + 1 compresse en vrac', [[3, null]]],
        ['Lot de pansements', null, null, self::PHARMACIE, null, null, [[9, null]]],
        ['Paire de gants', null, null, self::PHARMACIE, null, null, [[3, null]]],
        ['Écharpe', null, null, self::PHARMACIE, null, null, [[2, null]]],
        ['Couverture de survie', null, null, self::PHARMACIE, null, null, [[1, null]]],
        ['Masque', null, null, self::PHARMACIE, null, 'Lot de 2', [[1, null]]],
        ['Pansements en vrac', null, null, self::PHARMACIE, null, 'Quantité non comptée à l\'inventaire (« tout plein »)', []],
        ['Ciseaux', null, null, self::PHARMACIE, null, null, [[5, null]]],
        ['Pince à épiler pointue', null, null, self::PHARMACIE, null, null, [[1, null]]],
        ['Bande extensible petite', null, null, self::PHARMACIE, null, null, [[11, null]]],
        ['Bande extensible grande', null, null, self::PHARMACIE, null, null, [[10, null]]],
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Les deux garde-fous sont posés avant la première écriture. Sans eux, la reprise
        // passerait quand même : la catégorie et l'auteur sont résolus par sous-requête et
        // vaudraient NULL. On se retrouverait avec 87 articles sans catégorie, invisibles là
        // où le club les cherche, et 95 entrées de stock que personne n'a signées.
        $manquantes = $this->categoriesManquantes();
        if ($manquantes !== []) {
            $io->error(sprintf(
                'Catégories de stock absentes : %s. Les créer depuis /admin/stock/categories avant de relancer.',
                implode(', ', $manquantes),
            ));

            return Command::FAILURE;
        }

        if (!$this->auteurExiste()) {
            $io->error(sprintf('Aucun compte %s : c\'est lui qui signe les entrées d\'inventaire.', self::AUTEUR_EMAIL));

            return Command::FAILURE;
        }

        // Tout ou rien : la reprise pose 172 instructions, et un inventaire à moitié saisi est
        // pire que pas d'inventaire du tout — le stock afficherait des quantités fausses sans
        // que rien ne signale l'interruption. Une panne en cours de route rend la base intacte,
        // et il n'y a qu'à relancer.
        /** @var array{0: int, 1: int} $comptes */
        $comptes = $this->connection->transactional(function (): array {
            $articles = 0;
            $mouvements = 0;

            foreach (self::ARTICLES as [$nom, $marque, $couleur, $categorie, $typeVetement, $note, $lignes]) {
                $articles += $this->creerArticle($nom, $marque, $couleur, $categorie, $typeVetement, $note);

                if ($lignes !== []) {
                    $mouvements += $this->creerEntrees($nom, $marque, $couleur, $lignes);
                }
            }

            return [$articles, $mouvements];
        });

        [$articles, $mouvements] = $comptes;

        if ($articles === 0 && $mouvements === 0) {
            $io->success('Inventaire d\'août 2026 déjà repris : rien à faire.');

            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Inventaire d\'août 2026 repris : %d article(s) créé(s), %d entrée(s) de stock.',
            $articles,
            $mouvements,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function categoriesManquantes(): array
    {
        $attendues = [self::MATERIEL, self::PHARMACIE];

        $connues = $this->connection->fetchFirstColumn('SELECT name FROM stock_category');

        return array_values(array_diff($attendues, $connues));
    }

    private function auteurExiste(): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM "user" WHERE email = :email',
            ['email' => self::AUTEUR_EMAIL],
        );
    }

    /**
     * L'article n'est créé que s'il n'existe pas déjà sous la même désignation
     * (nom · marque · couleur) : la reprise reste rejouable sans doublon.
     */
    private function creerArticle(
        string $nom,
        ?string $marque,
        ?string $couleur,
        string $categorie,
        ?string $typeVetement,
        ?string $note,
    ): int {
        return (int) $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO stock_item (nom, marque, couleur, kind, type_vetement, note, actif, category_id)
                SELECT :nom, :marque, :couleur, 'equipement', :type_vetement, :note, true,
                       (SELECT id FROM stock_category WHERE name = :categorie)
                WHERE NOT EXISTS (
                    SELECT 1 FROM stock_item
                    WHERE nom = :nom
                      AND marque IS NOT DISTINCT FROM :marque
                      AND couleur IS NOT DISTINCT FROM :couleur
                )
                SQL,
            [
                'nom' => $nom,
                'marque' => $marque,
                'couleur' => $couleur,
                'type_vetement' => $typeVetement,
                'note' => $note,
                'categorie' => $categorie,
            ],
        );
    }

    /**
     * Toutes les déclinaisons d'un article partent dans un seul INSERT, gardé par « aucun
     * mouvement encore » : un article déjà géré entre-temps n'est pas réécrit.
     *
     * @param list<array{0: int, 1: ?string}> $lignes
     */
    private function creerEntrees(string $nom, ?string $marque, ?string $couleur, array $lignes): int
    {
        $valeurs = [];
        $params = [
            'date' => self::DATE_INVENTAIRE,
            'email' => self::AUTEUR_EMAIL,
            'nom' => $nom,
            'marque' => $marque,
            'couleur' => $couleur,
        ];

        foreach ($lignes as $index => [$quantite, $taille]) {
            $valeurs[] = sprintf('(CAST(:q%1$d AS INT), CAST(:t%1$d AS VARCHAR))', $index);
            $params['q' . $index] = $quantite;
            $params['t' . $index] = $taille;
        }

        return (int) $this->connection->executeStatement(
            sprintf(
                <<<'SQL'
                    INSERT INTO stock_movement (quantite, taille, type, source, created_at, item_id, created_by_id)
                    SELECT v.quantite, v.taille, 'entree', 'manuel', CAST(:date AS TIMESTAMP), i.id,
                           (SELECT id FROM "user" WHERE email = :email)
                    FROM stock_item i
                    CROSS JOIN (VALUES %s) AS v(quantite, taille)
                    WHERE i.nom = :nom
                      AND i.marque IS NOT DISTINCT FROM :marque
                      AND i.couleur IS NOT DISTINCT FROM :couleur
                      AND NOT EXISTS (SELECT 1 FROM stock_movement m WHERE m.item_id = i.id)
                    SQL,
                implode(', ', $valeurs),
            ),
            $params,
        );
    }
}
