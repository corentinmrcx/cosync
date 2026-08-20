<?php declare(strict_types=1);

namespace App\Service\Cle;

use App\Entity\Detenteur;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Repository\DetenteurRepository;
use App\Repository\DirigeantRepository;

/**
 * Rapproche le registre des clés (hors saison) de l'effectif (par saison), dans les
 * deux sens.
 *
 * C'est ce rapprochement qui permet de dire « cette personne détient une clé mais
 * n'est plus dirigeante cette année ». Il se fait sur le numéro de licence, seul
 * identifiant stable, avec le nom en repli pour les personnes qui n'en ont pas.
 *
 * Cette classe est le seul endroit qui sache comparer deux identités : le registre
 * et l'effectif n'écrivent jamais le même nom de la même façon, et une règle
 * dupliquée ailleurs finirait par diverger de celle-ci.
 */
final class DetenteurEffectifResolver
{
    public function __construct(
        private readonly DirigeantRepository $dirigeantRepo,
        private readonly DetenteurRepository $detenteurRepo,
    ) {}

    /** Le détenteur correspondant à ce dirigeant, s'il figure au registre des clés. */
    public function detenteurDe(Dirigeant $dirigeant): ?Detenteur
    {
        $numLicence = $dirigeant->getNumLicence();

        if ($numLicence !== null && $numLicence !== '') {
            $parLicence = $this->detenteurRepo->findByNumLicence($numLicence);

            if ($parLicence !== null) {
                return $parLicence;
            }
        }

        return $this->detenteurParNom($dirigeant->getNom(), $dirigeant->getPrenom());
    }

    /**
     * Repli quand le numéro de licence manque d'un côté ou de l'autre. La comparaison
     * passe par cleIdentite(), pas par une requête SQL : PostgreSQL ne replie pas les
     * accents sans extension, et le registre d'un club tient en quelques dizaines de
     * lignes.
     */
    public function detenteurParNom(string $nom, string $prenom): ?Detenteur
    {
        $recherchee = self::cleIdentite($nom, $prenom);

        foreach ($this->detenteurRepo->findAllOrdered() as $detenteur) {
            if (self::cleIdentite($detenteur->getNom(), $detenteur->getPrenom()) === $recherchee) {
                return $detenteur;
            }
        }

        return null;
    }

    /**
     * @param Detenteur[] $detenteurs
     *
     * @return array<int, Dirigeant> dirigeant de la saison, indexé par id de détenteur ;
     *                               absent pour les détenteurs hors effectif
     */
    public function pourSaison(Season $season, array $detenteurs): array
    {
        $parLicence = [];
        $parNom = [];

        foreach ($this->dirigeantRepo->findBySeason($season) as $dirigeant) {
            $numLicence = $dirigeant->getNumLicence();

            if ($numLicence !== null && $numLicence !== '') {
                $parLicence[$numLicence] = $dirigeant;
            }

            $parNom[self::cleIdentite($dirigeant->getNom(), $dirigeant->getPrenom())] = $dirigeant;
        }

        $rattachements = [];

        foreach ($detenteurs as $detenteur) {
            $numLicence = $detenteur->getNumLicence();
            $dirigeant = ($numLicence !== null && $numLicence !== '')
                ? ($parLicence[$numLicence] ?? null)
                : null;

            $dirigeant ??= $parNom[self::cleIdentite($detenteur->getNom(), $detenteur->getPrenom())] ?? null;

            if ($dirigeant !== null) {
                $rattachements[$detenteur->getId()] = $dirigeant;
            }
        }

        return $rattachements;
    }

    /**
     * Clé de rapprochement quand le numéro de licence manque.
     *
     * Les accents sont repliés : l'export FootClubs écrit « Marlene » là où la saisie
     * manuelle écrit « Marlène », et un import qui réaligne l'identité d'un dirigeant
     * suffisait alors à décrocher sa fiche du registre — elle ressortait « hors
     * effectif » alors que ses clés lui avaient bien été remises en tant que dirigeante.
     *
     * Le trait d'union, lui, n'est pas replié : « Anne-Marie » et « Anne Marie » restent
     * deux identités distinctes, faute de savoir laquelle des deux fait foi.
     */
    public static function cleIdentite(string $nom, string $prenom): string
    {
        return self::normaliser($nom) . '|' . self::normaliser($prenom);
    }

    /** Minuscules, sans accents, espaces réduits. */
    private static function normaliser(string $valeur): string
    {
        $lower = mb_strtolower(trim($valeur), 'UTF-8');
        $ascii = strtr($lower, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
            'ý' => 'y', 'ÿ' => 'y',
            'æ' => 'ae', 'œ' => 'oe', 'ß' => 'ss',
        ]);

        return (string) preg_replace('/\s+/u', ' ', $ascii);
    }
}
