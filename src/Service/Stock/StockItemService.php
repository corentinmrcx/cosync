<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\DTO\Stock\SuppressionArticle;
use App\Entity\GrilleTaille;
use App\Entity\StockItem;
use App\Enum\StockItemKind;
use App\Enum\StockItemVetementType;
use App\Enum\StockMovementSource;
use App\Repository\CommandeLigneRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\DotationModeleLigneRepository;
use App\Repository\StockItemRepository;
use App\Repository\StockMovementRepository;
use App\Repository\StockTailleNoteRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cycle de vie d'un article de stock.
 */
final class StockItemService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockMovementRepository $movementRepository,
        private readonly StockTailleNoteRepository $noteRepository,
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly DotationModeleLigneRepository $modeleLigneRepository,
        private readonly CommandeLigneRepository $commandeLigneRepository,
        private readonly StockItemRepository $itemRepository,
        private readonly StockTailleResolver $taillesResolver,
    ) {}

    public function creer(StockItem $item): void
    {
        $this->em->persist($item);
        $this->em->flush();
    }

    public function enregistrer(StockItem $item): void
    {
        $this->em->flush();
    }

    /**
     * Dit d'avance ce qu'il adviendra de l'article, pour que l'écran de confirmation
     * l'annonce au lieu de le constater.
     *
     * Un article qui ne porte plus rien et n'a été manipulé qu'à la main est une erreur de
     * saisie : il part pour de bon, avec ses mouvements — les garder sans leur article ne
     * dirait plus ce qui est entré ni ce qui est sorti. Dès qu'une dotation, une commande
     * ou une caisse l'a touché, la trace n'est plus une erreur mais une histoire : on
     * archive. Idem tant qu'il reste des unités : l'article existe encore physiquement.
     */
    public function analyserSuppression(StockItem $item): SuppressionArticle
    {
        // Taille par taille : un +5 en L compensé par un −5 en M donnerait un total nul
        // alors que le placard n'est pas vide.
        $parTaille = $this->movementRepository->getStockGroupedByTaille($item);
        $nonSoldees = array_filter($parTaille, static fn (int $quantite): bool => $quantite !== 0);
        if ($nonSoldees !== []) {
            $restant = array_sum($nonSoldees);

            return SuppressionArticle::aArchiver($restant > 0
                ? sprintf('il reste %d %s en stock.', $restant, $restant > 1 ? 'unités' : 'unité')
                : 'son stock par taille n\'est pas soldé.');
        }

        $mouvements = $this->movementRepository->count(['item' => $item]);
        $manuels = $this->movementRepository->count(['item' => $item, 'source' => StockMovementSource::MANUEL]);
        if ($mouvements !== $manuels) {
            return SuppressionArticle::aArchiver('des dotations, des réceptions de commande ou des ventes y sont rattachées : leur trace serait perdue.');
        }

        foreach ($this->referencesBloquantes($item) as $motif => $compte) {
            if ($compte > 0) {
                return SuppressionArticle::aArchiver($motif);
            }
        }

        return SuppressionArticle::supprimable($mouvements);
    }

    /** Sort l'article des listes sans rien perdre. Réversible par `restaurer()`. */
    public function archiver(StockItem $item): void
    {
        $item->setActif(false);
        $this->em->flush();
    }

    /**
     * Supprime l'article si l'analyse l'autorise, l'archive sinon. L'archivage est le cas
     * normal : un article sorti du catalogue reste dans l'historique du club.
     *
     * @return bool true si l'article a été archivé, false s'il a été réellement supprimé
     *
     * @throws \DomainException si une dotation ou une commande le référence encore
     */
    public function supprimerOuArchiver(StockItem $item): bool
    {
        if (!$this->analyserSuppression($item)->supprimable) {
            $this->archiver($item);

            return true;
        }

        // Les mouvements et les notes de taille n'existent que par leur article : ils
        // partent avec lui, sans quoi la suppression bute sur leur clé étrangère.
        foreach ($this->movementRepository->findByItem($item) as $movement) {
            $this->em->remove($movement);
        }
        foreach ($this->noteRepository->findByItem($item) as $note) {
            $this->em->remove($note);
        }

        try {
            $this->em->remove($item);
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException) {
            throw new \DomainException(sprintf('Impossible de supprimer "%s" : il est référencé par une dotation ou une commande.', $item->getNom()));
        }

        return false;
    }

    /**
     * Références qui interdisent la suppression, motif d'archivage en clé.
     *
     * @return array<string, int>
     */
    private function referencesBloquantes(StockItem $item): array
    {
        return [
            'il figure dans un kit de dotation.' => $this->modeleLigneRepository->count(['stockItem' => $item]),
            'il est attendu dans une dotation à remettre.' => $this->besoinRepository->count(['stockItem' => $item]),
            'il est servi à la place d\'un article de kit, dans une dotation à remettre.' => $this->besoinRepository->countByArticleEcoulement($item),
            'des articles sont en cours d\'écoulement à sa place.' => $this->itemRepository->countSubstituts($item),
            'il figure sur un bon de commande.' => $this->commandeLigneRepository->count(['stockItem' => $item]),
        ];
    }

    /**
     * Applique les champs conditionnels au type d'article (équipement vs épicerie) sur un StockItem.
     * Centralise la règle : un vêtement n'a pas de taille figée (déclinaisons de stock), l'épicerie
     * porte sa contenance dans « taille ».
     *
     * @throws \DomainException si la grille ne traduit pas l'échelle du type de vêtement
     */
    public function applyEditableFields(
        StockItem $item,
        ?StockItemKind $kind,
        ?string $marque,
        ?string $couleur,
        ?string $taille,
        ?StockItemVetementType $typeVetement,
        ?GrilleTaille $grille = null,
    ): void {
        $this->typeChangeableSansCasserLEcoulement($item, $kind, $typeVetement);

        $item->setKind($kind);
        $item->setMarque($marque ?: null);

        if ($kind === StockItemKind::EQUIPEMENT) {
            $item->setTaille(null);
            $item->setCouleur($couleur ?: null);
            $item->setTypeVetement($typeVetement);
            $item->setGrilleTaille($this->grilleAdmise($item, $grille));
        } else {
            $item->setTaille($taille ?: null);
            $item->setCouleur(null);
            $item->setTypeVetement(null);
            $item->setGrilleTaille(null);
        }
    }

    /**
     * Un article engagé dans une correspondance d'écoulement ne change pas de type de
     * vêtement — ni ne quitte l'équipement — sans qu'on retire d'abord la règle.
     *
     * C'est l'invariant du §4 : les deux côtés doivent porter le même type, faute de quoi la
     * dotation lit la taille du bas pour servir un haut. Le laisser filer serait silencieux
     * et invisible — la fiche article ne montre plus la règle qu'en lecture — alors que le
     * refus dit exactement quoi faire, et où.
     *
     * @throws \DomainException
     */
    private function typeChangeableSansCasserLEcoulement(
        StockItem $item,
        ?StockItemKind $kind,
        ?StockItemVetementType $typeVetement,
    ): void {
        $nouveau = $kind === StockItemKind::EQUIPEMENT ? $typeVetement : null;

        if ($nouveau === $item->getTypeVetement()) {
            return;
        }

        $engage = $item->getRemplaceArticle() !== null || $this->itemRepository->countSubstituts($item) > 0;

        if ($engage) {
            throw new \DomainException(
                'Cet article fait partie d\'une correspondance d\'écoulement : retirez-la depuis '
                . '« Stock → Écoulement » avant de changer son type de vêtement.',
            );
        }
    }

    /**
     * Déclare (ou retire) l'article de dotation que celui-ci écoule : le stock Nike qu'on sert
     * tant qu'il en reste, avant de commander de l'ERIMA.
     *
     * Quatre refus, et chacun correspond à une dotation qui sortirait fausse :
     *
     * - **soi-même** : un article ne s'écoule pas à sa propre place ;
     * - **une chaîne** (Nike → Adidas → ERIMA) : l'arbitrage ne remonte qu'un cran, la cible
     *   doit donc être un vrai article de kit. Deux anciens fournisseurs pointent chacun
     *   directement sur le nouveau, ce qui couvre le cas réel ;
     * - **un article déjà écoulé par d'autres** : il est une cible, il ne peut pas devenir
     *   substitut sans créer cette même chaîne dans l'autre sens ;
     * - **une échelle de tailles différente** : le type de vêtement dit quel champ du dossier
     *   lire. Écouler un short à la place d'un maillot ferait servir la taille du bas sur le
     *   haut, et la sortie de stock partirait dans la mauvaise déclinaison.
     *
     * @throws \DomainException
     */
    public function appliquerEcoulement(StockItem $item, ?StockItem $cible): void
    {
        // L'épicerie n'a pas de dotation : la règle n'a aucun sens sur une bouteille.
        if ($cible === null || $item->getKind() !== StockItemKind::EQUIPEMENT) {
            $item->setRemplaceArticle(null);

            return;
        }

        // Un article encore à créer n'a pas d'id : il ne peut être ni sa propre cible, ni
        // déjà remplacé par d'autres. Les deux contrôles qui en dépendent ne le concernent pas.
        $connu = $this->em->contains($item);

        if ($connu && $cible->getId() === $item->getId()) {
            throw new \DomainException('Un article ne peut pas s\'écouler à sa propre place.');
        }

        if ($cible->estArticleDEcoulement()) {
            throw new \DomainException(sprintf('« %s » est lui-même un article en cours d\'écoulement : indiquez plutôt l\'article du kit qu\'il remplace.', $cible->getNom()));
        }

        if ($connu && $this->itemRepository->countSubstituts($item) > 0) {
            throw new \DomainException(sprintf('« %s » est déjà remplacé par d\'autres articles : il ne peut pas en remplacer un à son tour.', $item->getNom()));
        }

        if ($cible->getTypeVetement() !== $item->getTypeVetement()) {
            throw new \DomainException(sprintf('« %s » ne se mesure pas comme cet article : les deux doivent porter le même type de vêtement pour que la taille du licencié se lise au bon endroit.', $cible->getNom()));
        }

        $item->setRemplaceArticle($cible);
    }

    /**
     * Le formulaire ne propose déjà que les grilles de la bonne échelle, mais un onglet resté
     * ouvert pendant qu'un type de vêtement changeait rattacherait sinon une grille de
     * pointures à un maillot — et toutes ses dotations sortiraient sans taille.
     *
     * @throws \DomainException
     */
    private function grilleAdmise(StockItem $item, ?GrilleTaille $grille): ?GrilleTaille
    {
        if ($grille === null) {
            return null;
        }

        $type = $this->taillesResolver->profil($item)->type();
        if ($type !== $grille->getType()) {
            throw new \DomainException(sprintf('La grille « %s » se mesure en %s : elle ne convient pas à cet article.', $grille->getNom(), mb_strtolower($grille->getType()->label())));
        }

        return $grille;
    }

    public function restaurer(StockItem $item): void
    {
        $item->setActif(true);
        $this->em->flush();
    }
}
