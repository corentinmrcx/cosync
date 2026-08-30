<?php declare(strict_types=1);

namespace App\Service\Pdf;

use App\Entity\MatchDomicile;

/**
 * La géométrie du flyer A5, en millimètres.
 *
 * Elle existe parce que deux décisions ne peuvent pas être prises en CSS : **combien de
 * rencontres tiennent sur la page**, et **où poser le bloc de contacts**. DomPDF n'a ni
 * flexbox ni `calc()` sur des hauteurs de contenu ; le pied ne peut donc pas se centrer
 * tout seul dans la place qui reste. On calcule, et le template ne fait que placer.
 *
 * ⚠️ Les cotes ci-dessous sont **relevées sur des rendus réels**, pas estimées : hauteur
 * de l'en-tête de tableau, d'une ligne simple, d'une ligne qui se replie. Les changer sans
 * remesurer déplacerait le pied ou ferait déborder la page.
 */
final class FlyerGeometrie
{
    /** Haut du tableau : la réserve de l'en-tête (bandeau rouge + logo qui déborde). */
    private const HAUT_TABLEAU = 50.0;

    /** Ligne des intitulés de colonnes. */
    private const ENTETE_TABLEAU = 6.9;

    /** Une rencontre dont le libellé tient sur une ligne. */
    private const LIGNE_SIMPLE = 8.7;

    /** Une rencontre dont le libellé se replie — un plateau à trois clubs invités. */
    private const LIGNE_DOUBLE = 13.3;

    /**
     * La mention « … et N autres rencontres ». Plus basse qu'une rencontre : corps plus
     * petit, et surtout pas de filet de séparation sous elle.
     */
    private const LIGNE_OMIS = 5.9;

    /** Filet rouge, les deux contacts sur deux lignes, l'adresse mail. */
    private const HAUTEUR_PIED = 22.0;

    /**
     * Blanc entre la dernière ligne du tableau et le filet rouge du pied.
     *
     * Ce n'est pas une valeur d'ajustement : c'est **l'écart mesuré entre le bas du blason
     * et l'en-tête du tableau** (41,9 → 51,1 mm sur le rendu). Le tableau respire donc
     * pareil au-dessus et en dessous, quelle que soit la longueur de la liste.
     */
    private const ECART_SOUS_TABLEAU = 9.2;

    /** Hauteur du paragraphe « Aucun match » : son rembourrage haut et sa ligne de texte. */
    private const HAUTEUR_VIDE = 17.8;

    /** Haut du bandeau « Tous derrière nos équipes ! ». */
    private const HAUT_BANDEAU = 195.1;

    /** Blanc conservé entre le bas du pied et le bandeau, pour ne jamais le toucher. */
    private const GARDE = 4.0;

    /**
     * Au-delà de cette longueur, la colonne « Rencontre » se replie sur une deuxième
     * ligne. Mesurée sur la largeur réelle de la colonne, en Montserrat 8 pt.
     */
    private const LONGUEUR_LIGNE = 48;

    /**
     * Ne garde que les rencontres qui tiennent réellement sur la page.
     *
     * Le compte se fait en **millimètres** et non en nombre de matchs : un plateau qui
     * accueille trois clubs occupe une ligne et demie, et deux plateaux dans le mois
     * suffisaient à faire déborder un plafond exprimé en rencontres.
     *
     * @param list<MatchDomicile> $matchs
     *
     * @return array{matchs: list<MatchDomicile>, omis: int, piedTop: float}
     */
    public function ajuster(array $matchs): array
    {
        if ($matchs === []) {
            return ['matchs' => [], 'omis' => 0, 'piedTop' => $this->positionPied(self::HAUTEUR_VIDE)];
        }

        $disponible = self::HAUT_BANDEAU - self::GARDE - self::HAUTEUR_PIED
            - self::ECART_SOUS_TABLEAU - self::HAUT_TABLEAU;

        $retenus = $this->remplir($matchs, $disponible);

        // La mention « … et N autres rencontres » occupe elle-même une ligne du tableau :
        // dès qu'il y a des omis, elle doit être **réservée avant** de remplir, sinon elle
        // s'ajoute par-dessus un tableau déjà plein et pousse le pied sous le bandeau.
        if (count($retenus) < count($matchs)) {
            $retenus = $this->remplir($matchs, $disponible - self::LIGNE_OMIS);
        }

        $omis = count($matchs) - count($retenus);
        $hauteur = $this->hauteurTableau($retenus) + ($omis > 0 ? self::LIGNE_OMIS : 0.0);

        return [
            'matchs' => $retenus,
            'omis' => $omis,
            'piedTop' => $this->positionPied($hauteur),
        ];
    }

    /**
     * Les rencontres qui tiennent dans une hauteur donnée, en-tête de tableau compris.
     *
     * @param list<MatchDomicile> $matchs
     *
     * @return list<MatchDomicile>
     */
    private function remplir(array $matchs, float $disponible): array
    {
        $retenus = [];
        $hauteur = self::ENTETE_TABLEAU;

        foreach ($matchs as $match) {
            $coute = $this->hauteurLigne($match);

            if ($hauteur + $coute > $disponible) {
                break;
            }

            $hauteur += $coute;
            $retenus[] = $match;
        }

        return $retenus;
    }

    /** @param list<MatchDomicile> $matchs */
    private function hauteurTableau(array $matchs): float
    {
        return array_reduce(
            $matchs,
            fn (float $total, MatchDomicile $match): float => $total + $this->hauteurLigne($match),
            self::ENTETE_TABLEAU,
        );
    }

    /**
     * Le pied suit la fin du tableau à distance constante : **le même blanc que sous le
     * blason**, cf. ECART_SOUS_TABLEAU.
     *
     * Il ne se centre pas dans la place restante — essayé, et écarté : le blanc au-dessus
     * des contacts variait alors du simple au décuple selon le mois, quand celui sous le
     * logo ne bouge jamais. Ce qui reste tombe donc **sous** le pied, au-dessus du bandeau
     * d'appel, où l'œil ne le compte pas.
     */
    private function positionPied(float $hauteurTableau): float
    {
        return self::HAUT_TABLEAU + $hauteurTableau + self::ECART_SOUS_TABLEAU;
    }

    private function hauteurLigne(MatchDomicile $match): float
    {
        return mb_strlen($match->libelleRencontre()) > self::LONGUEUR_LIGNE
            ? self::LIGNE_DOUBLE
            : self::LIGNE_SIMPLE;
    }
}
