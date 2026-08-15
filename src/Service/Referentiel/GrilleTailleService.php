<?php declare(strict_types=1);

namespace App\Service\Referentiel;

use App\Entity\GrilleTaille;
use App\Entity\GrilleTailleValeur;
use App\Entity\Taille;
use App\Enum\TailleType;
use App\Repository\GrilleTailleRepository;
use App\Repository\StockItemRepository;
use App\Repository\TailleRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Écriture des grilles de tailles.
 *
 * Une grille ne vaut que si sa traduction est déterministe : une taille déclarée couverte par
 * deux libellés fournisseur ne saurait pas dire lequel servir. C'est la règle que ce service
 * fait respecter, avec deux autres qui la soutiennent — on ne couvre que des tailles qu'une
 * personne peut réellement déclarer, et les deux côtés restent dans l'échelle de la grille.
 */
final class GrilleTailleService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GrilleTailleRepository $repository,
        private readonly TailleRepository $tailleRepository,
        private readonly StockItemRepository $itemRepository,
    ) {}

    /** @throws \DomainException si le nom est vide ou déjà pris */
    public function creer(string $nom, TailleType $type): GrilleTaille
    {
        $grille = (new GrilleTaille())->setType($type);
        $this->renommerSansFlush($grille, $nom);

        $this->em->persist($grille);
        $this->em->flush();

        return $grille;
    }

    /** @throws \DomainException si le nom est vide ou déjà pris */
    public function renommer(GrilleTaille $grille, string $nom): void
    {
        $this->renommerSansFlush($grille, $nom);
        $this->em->flush();
    }

    /**
     * @throws \DomainException si des articles s'en servent — les délier d'abord, sinon leur
     *                          stock se retrouverait rangé sous des déclinaisons orphelines
     */
    public function supprimer(GrilleTaille $grille): void
    {
        $articles = $this->itemRepository->count(['grilleTaille' => $grille]);
        if ($articles > 0) {
            throw new \DomainException(sprintf('Impossible de supprimer « %s » : %d article%s s\'en sert%s. Retirez-la de ces articles d\'abord.', $grille->getNom(), $articles, $articles > 1 ? 's' : '', $articles > 1 ? 'ent' : ''));
        }

        $this->em->remove($grille);
        $this->em->flush();
    }

    /**
     * Ajoute une ligne de traduction : un libellé fournisseur et les tailles déclarées qu'il
     * habille.
     *
     * @param int[] $couvertureIds
     *
     * @throws \DomainException
     */
    public function ajouterValeur(GrilleTaille $grille, ?int $cibleId, array $couvertureIds): void
    {
        $cible = $this->tailleDuType($grille, $cibleId, 'Choisissez la taille du fournisseur.');

        foreach ($grille->getValeurs() as $existante) {
            if ($existante->getCible()->getId() === $cible->getId()) {
                throw new \DomainException(sprintf('« %s » figure déjà dans cette grille.', $cible->getLibelle()));
            }
        }

        $valeur = (new GrilleTailleValeur())->setCible($cible);
        $grille->addValeur($valeur);

        $this->appliquerCouvertures($valeur, $couvertureIds);

        $this->em->persist($valeur);
        $this->em->flush();
    }

    /**
     * @param int[] $couvertureIds
     *
     * @throws \DomainException
     */
    public function modifierValeur(GrilleTailleValeur $valeur, array $couvertureIds): void
    {
        $this->appliquerCouvertures($valeur, $couvertureIds);
        $this->em->flush();
    }

    public function supprimerValeur(GrilleTailleValeur $valeur): void
    {
        $valeur->getGrille()->removeValeur($valeur);

        $this->em->remove($valeur);
        $this->em->flush();
    }

    /**
     * Tailles déclarables que la grille ne traduit pas encore. Non bloquant : l'écran s'en sert
     * pour prévenir, parce qu'une personne dans ce cas verra son besoin de dotation rester
     * « à renseigner ».
     *
     * @return list<string>
     */
    public function taillesNonCouvertes(GrilleTaille $grille): array
    {
        $couvertes = [];
        foreach ($grille->getValeurs() as $valeur) {
            foreach ($valeur->libellesCouverts() as $libelle) {
                $couvertes[$libelle] = true;
            }
        }

        $manquantes = [];
        foreach ($this->declarables($grille->getType()) as $taille) {
            if (!isset($couvertes[$taille->getLibelle()])) {
                $manquantes[] = $taille->getLibelle();
            }
        }

        return $manquantes;
    }

    /**
     * Tailles qu'une valeur de cette grille peut couvrir : celles que les formulaires
     * proposent, dans l'échelle de la grille. Les étiquetages fournisseur en sont exclus —
     * personne ne déclare « 128 », c'est justement ce que la grille produit.
     *
     * @return list<Taille>
     */
    public function declarables(TailleType $type): array
    {
        return array_values(array_filter(
            $this->tailleRepository->findAllOrdered(),
            static fn (Taille $t): bool => $t->getType() === $type && $t->isProposeeAuxLicencies(),
        ));
    }

    /**
     * Tailles proposables comme libellé fournisseur : tout le référentiel de l'échelle. Une
     * déclinaison réservée au stock y figure — c'est même son emploi le plus courant.
     *
     * @return list<Taille>
     */
    public function ciblesPossibles(TailleType $type): array
    {
        return array_values(array_filter(
            $this->tailleRepository->findAllOrdered(),
            static fn (Taille $t): bool => $t->getType() === $type,
        ));
    }

    /**
     * @param int[] $couvertureIds
     *
     * @throws \DomainException
     */
    private function appliquerCouvertures(GrilleTailleValeur $valeur, array $couvertureIds): void
    {
        $grille = $valeur->getGrille();

        $declarables = [];
        foreach ($this->declarables($grille->getType()) as $taille) {
            $declarables[$taille->getId()] = $taille;
        }

        $voulues = [];
        foreach ($couvertureIds as $id) {
            $taille = $declarables[$id] ?? null;
            if ($taille === null) {
                throw new \DomainException('Une taille couverte doit être proposée aux licenciés et de la même échelle que la grille.');
            }

            $this->assertNonCouverteAilleurs($valeur, $taille);
            $voulues[$id] = $taille;
        }

        foreach ($valeur->getCouvertures() as $actuelle) {
            if (!isset($voulues[$actuelle->getId()])) {
                $valeur->removeCouverture($actuelle);
            }
        }

        foreach ($voulues as $taille) {
            $valeur->addCouverture($taille);
        }
    }

    /** @throws \DomainException si une autre valeur de la même grille couvre déjà cette taille */
    private function assertNonCouverteAilleurs(GrilleTailleValeur $valeur, Taille $taille): void
    {
        foreach ($valeur->getGrille()->getValeurs() as $autre) {
            if ($autre !== $valeur && $autre->couvre($taille->getLibelle())) {
                throw new \DomainException(sprintf('La taille « %s » est déjà couverte par « %s » : une taille déclarée ne peut mener qu\'à un seul libellé.', $taille->getLibelle(), $autre->getCible()->getLibelle()));
            }
        }
    }

    /** @throws \DomainException */
    private function tailleDuType(GrilleTaille $grille, ?int $id, string $messageSiAbsente): Taille
    {
        if ($id === null) {
            throw new \DomainException($messageSiAbsente);
        }

        $taille = $this->tailleRepository->find($id);
        if ($taille === null || $taille->getType() !== $grille->getType()) {
            throw new \DomainException(sprintf('Cette taille n\'appartient pas à l\'échelle « %s ».', $grille->getType()->label()));
        }

        return $taille;
    }

    /** @throws \DomainException */
    private function renommerSansFlush(GrilleTaille $grille, string $nom): void
    {
        $nom = trim($nom);
        if ($nom === '') {
            throw new \DomainException('Le nom d\'une grille ne peut pas être vide.');
        }

        foreach ($this->repository->findAllOrdered() as $autre) {
            if ($autre !== $grille && $autre->getNom() === $nom) {
                throw new \DomainException(sprintf('Une grille « %s » existe déjà.', $nom));
            }
        }

        $grille->setNom($nom);
    }
}
