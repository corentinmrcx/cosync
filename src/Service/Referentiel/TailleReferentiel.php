<?php declare(strict_types=1);

namespace App\Service\Referentiel;

use App\Entity\Taille;
use App\Enum\TailleType;
use App\Repository\TailleRepository;

/**
 * Lecture du référentiel des tailles, réglé en base depuis /admin/club/tailles.
 *
 * Deux publics à ne pas confondre. Les formulaires ne proposent que ce qu'une personne sait
 * dire d'elle-même — un parent connaît l'âge de son enfant, pas l'étiquette du fournisseur.
 * Le stock, lui, range ce qui est marqué sur le carton : « 128 », « L enfant ». D'où le
 * couple `proposeesAuxLicencies()` / `pourLeStock()`.
 *
 * Le référentiel est relu une fois par requête : les écrans de stock l'interrogent une fois
 * par article, et il tient en quelques dizaines de lignes.
 */
final class TailleReferentiel
{
    /** @var list<Taille>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly TailleRepository $repository,
    ) {}

    /**
     * Toutes les tailles d'un type, dans l'ordre d'affichage — y compris celles réservées
     * au stock.
     *
     * @return list<string>
     */
    public function pourLeStock(TailleType $type): array
    {
        return $this->libelles($this->duType($type));
    }

    /**
     * Tailles proposées dans les formulaires public et admin, groupées comme elles s'y
     * affichent. Un groupe vide de nom ferme la liste sans intitulé.
     *
     * @return list<array{label: ?string, options: list<string>}>
     */
    public function groupesProposes(TailleType $type): array
    {
        $groupes = [];
        foreach ($this->duType($type) as $taille) {
            if (!$taille->isProposeeAuxLicencies()) {
                continue;
            }

            $groupes[$taille->getGroupe() ?? ''][] = $taille->getLibelle();
        }

        $out = [];
        foreach ($groupes as $label => $options) {
            $out[] = ['label' => $label === '' ? null : $label, 'options' => $options];
        }

        return $out;
    }

    /**
     * Choix groupés pour un ChoiceType Symfony : ['Tailles adultes' => ['M' => 'M', …]].
     * Les tailles sans groupe sont proposées à plat, comme le fait Symfony.
     *
     * @return array<string, array<string, string>|string>
     */
    public function choixGroupes(TailleType $type): array
    {
        $choix = [];
        foreach ($this->groupesProposes($type) as $groupe) {
            $options = array_combine($groupe['options'], $groupe['options']);

            if ($groupe['label'] === null) {
                $choix += $options;

                continue;
            }

            $choix[$groupe['label']] = $options;
        }

        return $choix;
    }

    /**
     * Ordre d'affichage de deux tailles : celui du référentiel, puis les valeurs qu'il ne
     * connaît pas — d'abord les nombres dans l'ordre des nombres, le reste ensuite. Une
     * taille retirée du référentiel reste ainsi affichable là où elle a été employée.
     */
    public function comparer(string $a, string $b): int
    {
        $cle = fn (string $libelle): array => [
            $this->rang($libelle),
            is_numeric($libelle) ? (int) $libelle : \PHP_INT_MAX,
            $libelle,
        ];

        return $cle($a) <=> $cle($b);
    }

    /** Rang d'affichage d'un libellé ; les valeurs hors référentiel ferment la liste. */
    public function rang(string $libelle): int
    {
        foreach ($this->toutes() as $index => $taille) {
            if ($taille->getLibelle() === $libelle) {
                return $index;
            }
        }

        return \PHP_INT_MAX;
    }

    /** @return list<Taille> */
    public function toutes(): array
    {
        return $this->cache ??= array_values($this->repository->findAllOrdered());
    }

    /** Vide le cache de requête — à appeler après toute écriture sur le référentiel. */
    public function oublier(): void
    {
        $this->cache = null;
    }

    /**
     * @param list<Taille> $tailles
     *
     * @return list<string>
     */
    private function libelles(array $tailles): array
    {
        return array_map(static fn (Taille $t): string => $t->getLibelle(), $tailles);
    }

    /** @return list<Taille> */
    private function duType(TailleType $type): array
    {
        return array_values(array_filter(
            $this->toutes(),
            static fn (Taille $t): bool => $t->getType() === $type,
        ));
    }
}
