<?php declare(strict_types=1);

namespace App\Service\Referentiel;

use App\Entity\Dirigeant;
use App\Entity\Fonction;
use App\Entity\Team;
use App\Repository\FonctionRepository;

/**
 * Met en forme les fonctions d'un dirigeant. N'écrit rien.
 *
 * Le nom de l'équipe n'est pas stocké dans le libellé : une fonction marquée
 * `porteEquipe` le reprend de la fiche au moment de l'affichage, si bien qu'un
 * dirigeant qui change d'équipe change de fonction sans qu'on y touche.
 */
final class FonctionPresenter
{
    /** Le séparateur entre deux fonctions d'une même personne, sur un document comme à l'écran. */
    private const SEPARATEUR = ' · ';

    public function __construct(
        private readonly FonctionRepository $repository,
    ) {}

    /** « Entraîneur » + U16 → « Entraîneur U16 ». Sans équipe, le libellé nu. */
    public function libelle(Fonction $fonction, ?Team $team): string
    {
        if (!$fonction->isPorteEquipe() || $team === null) {
            return $fonction->getLibelle();
        }

        return $fonction->getLibelle() . ' ' . $team->getName();
    }

    /** @return string[] Les fonctions du dirigeant, dans l'ordre du référentiel. */
    public function libelles(Dirigeant $dirigeant): array
    {
        $team = $dirigeant->getTeam();

        return array_map(
            fn (Fonction $fonction): string => $this->libelle($fonction, $team),
            $dirigeant->getFonctions()->toArray(),
        );
    }

    /**
     * Le référentiel pour le sélecteur multiple de la fiche.
     *
     * Une **liste**, pas les `vars.choices` du formulaire : ceux-ci sont indexés par
     * libellé, et le `json_encode` du composant en faisait un objet JavaScript sur lequel
     * `.filter()` n'existe pas. Le champ restait vide, sans la moindre erreur visible.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(): array
    {
        return array_map(
            static fn (Fonction $f): array => [
                'value' => (string) $f->getId(),
                // Ce que le libellé deviendra une fois l'équipe de la fiche accolée.
                'label' => $f->isPorteEquipe() ? $f->getLibelle() . ' (+ équipe)' : $f->getLibelle(),
            ],
            $this->repository->findAllOrdered(),
        );
    }

    /** @return list<string> Les identifiants des fonctions déjà portées, tels que les attend le sélecteur. */
    public function valeursChoisies(?Dirigeant $dirigeant): array
    {
        if ($dirigeant === null) {
            return [];
        }

        return array_map(
            static fn (Fonction $f): string => (string) $f->getId(),
            $dirigeant->getFonctions()->toArray(),
        );
    }

    /** Les fonctions du dirigeant en une ligne — null s'il n'en porte aucune. */
    public function phrase(Dirigeant $dirigeant): ?string
    {
        $libelles = $this->libelles($dirigeant);

        return $libelles === [] ? null : implode(self::SEPARATEUR, $libelles);
    }
}
