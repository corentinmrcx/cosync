<?php declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réaligne document_signable.code et .file_prefix sur le titre du document.
 *
 * Les deux étaient dérivés du titre par iconv('ASCII//TRANSLIT') : selon la locale, cette
 * fonction rend « é » sous la forme « 'e », et le nettoyage qui suit transformait
 * l'apostrophe en séparateur. D'où les fichiers « R_EGLEMENT_INT_ERIEUR_MARCOUX_Maxence »
 * sur le Drive du club. Les préfixes hérités de la reprise des règlements figés (« RI »,
 * « RI_Dirigeant ») sont réalignés eux aussi : le Drive n'ayant pas encore servi en
 * production, aucune continuité de nommage n'est à préserver.
 *
 * NON DESTRUCTIF : aucune signature, aucun PDF n'est touché. Un `code` n'est réécrit que
 * si sa nouvelle valeur est libre dans la saison — l'index unique (season, code) prime.
 */
final class Version20260814150000 extends AbstractMigration
{
    /**
     * Copie de App\Service\Drive\DriveFilenameSanitizer : une migration doit rester
     * exécutable telle quelle, même si le service évolue ensuite.
     *
     * @var array<string, string>
     */
    private const TRANSLITERATION = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ą' => 'a',
        'æ' => 'ae',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'ð' => 'd', 'ď' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ğ' => 'g',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ı' => 'i',
        'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
        'œ' => 'oe',
        'ř' => 'r',
        'ś' => 's', 'š' => 's', 'ş' => 's',
        'ß' => 'ss',
        'ţ' => 't', 'ť' => 't',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
    ];

    public function getDescription(): string
    {
        return 'Réaligne les codes et préfixes de fichiers des documents signables sur leur titre';
    }

    public function up(Schema $schema): void
    {
        $documents = $this->connection->fetchAllAssociative(
            'SELECT id, season_id, code, titre FROM document_signable ORDER BY id'
        );

        $codesActuels = [];
        $cibles = [];

        foreach ($documents as $document) {
            $codesActuels[(int) $document['season_id']][] = (string) $document['code'];
            $cibles[(int) $document['id']] = $this->slug((string) $document['titre']);
        }

        foreach ($documents as $document) {
            $id = (int) $document['id'];
            $saison = (int) $document['season_id'];
            $cible = $cibles[$id];

            if ($cible === '') {
                continue;
            }

            $this->addSql('UPDATE document_signable SET file_prefix = ? WHERE id = ?', [$this->prefixe($cible), $id]);

            $code = substr($cible, 0, 60);

            if ($code !== (string) $document['code'] && $this->codeLibre($code, $id, $saison, $documents, $cibles, $codesActuels)) {
                $this->addSql('UPDATE document_signable SET code = ? WHERE id = ?', [$code, $id]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Rien à défaire : remettre des accents cassés dans des identifiants n'aurait
        // aucun intérêt, et aucune donnée signée ne dépend de ces deux colonnes.
        $this->addSql('SELECT 1');
    }

    /**
     * Le code doit rester unique par saison. On refuse la réécriture dès qu'un autre
     * document de la saison vise ce code ou le porte déjà : il garde alors son ancien
     * code, laid mais fonctionnel — mieux qu'une migration qui échoue sur la contrainte.
     *
     * @param list<array<string, mixed>> $documents
     * @param array<int, string>         $cibles
     * @param array<int, list<string>>   $codesActuels
     */
    private function codeLibre(string $code, int $id, int $saison, array $documents, array $cibles, array $codesActuels): bool
    {
        if (in_array($code, $codesActuels[$saison] ?? [], true)) {
            return false;
        }

        foreach ($documents as $autre) {
            if ((int) $autre['id'] !== $id
                && (int) $autre['season_id'] === $saison
                && $cibles[(int) $autre['id']] === $code) {
                return false;
            }
        }

        return true;
    }

    /** La colonne fait 30 caractères : on coupe sur un séparateur, jamais en plein mot. */
    private function prefixe(string $code): string
    {
        if (strlen($code) <= 30) {
            return $code;
        }

        $coupe = substr($code, 0, 30);
        $separateur = strrpos($coupe, '_');

        return trim($separateur !== false && $separateur >= 10 ? substr($coupe, 0, $separateur) : $coupe, '_');
    }

    private function slug(string $titre): string
    {
        $slug = strtr(mb_strtolower($titre, 'UTF-8'), self::TRANSLITERATION);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $slug), '_');
    }
}
