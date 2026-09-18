<?php declare(strict_types=1);

namespace App\DTO;

/**
 * La taille d'une ligne de suivi, telle que l'admin doit la lire devant les cartons.
 *
 * Le libellé du stock ne suffisait pas : « 37 » désignait le carton Erima 37-40, et se lisait
 * comme la pointure d'un joueur qui chausse du 39.
 */
final class DotationLigneTaille
{
    /**
     * @param ?string                                      $etiquette  ce qui est écrit sur le carton (« 37-40 »), null si la ligne n'a pas de taille
     * @param ?string                                      $declaree   ce que la personne a déclaré (« pointure 39 ») — toujours pour une pointure, null pour un vêtement dont l'étiquette le dit déjà
     * @param list<array{valeur: string, libelle: string}> $options    déclinaisons proposées à la correction
     * @param ?string                                      $selection  valeur présélectionnée à l'ouverture du sélecteur, null quand la taille suit le dossier
     * @param bool                                         $relachable vrai si « Automatique » rend la taille au dossier — seul le recalcul d'une ligne à donner la relit
     */
    public function __construct(
        public readonly ?string $etiquette,
        public readonly ?string $declaree,
        public readonly array $options,
        public readonly ?string $selection,
        public readonly bool $relachable,
    ) {}
}
