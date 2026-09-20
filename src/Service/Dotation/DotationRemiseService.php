<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\Entity\DotationBesoin;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\DotationBesoinStatut;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Service\Stock\StockMovementService;
use App\Service\Stock\StockTailleResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remise effective d'une dotation : ce qui sort réellement du stock, et la taille à laquelle
 * il en sort. Le texte à floquer relève de DotationFlocageService — il se règle sur le kit,
 * pas sur le mouvement de stock ; la mise de côté qui précède relève de
 * DotationPreparationService, qui ne touche justement pas au stock.
 */
final class DotationRemiseService
{
    public function __construct(
        private readonly StockMovementService $stockService,
        private readonly StockTailleResolver $tailles,
        private readonly DotationResolver $resolver,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Marque un besoin comme remis : crée le mouvement de sortie et passe le statut à « donné ».
     *
     * Accepte les deux amonts. L'écran fait passer par la préparation, mais le service ne
     * l'exige pas : une dotation attrapée dans l'armoire pour quelqu'un qui passe se remet
     * d'un geste, et rien dans le domaine ne dépend d'un sac qui aurait existé avant.
     */
    public function marquerRemis(DotationBesoin $besoin, ?User $user): void
    {
        if ($besoin->getStatut()->estRemis()) {
            return;
        }

        $besoin
            ->setStatut(DotationBesoinStatut::DONNE)
            ->setMouvementSortie($this->sortirDuStock($besoin, $besoin->getTaille(), $user));

        $this->em->flush();
    }

    /**
     * Remise d'un autre article que celui prévu : on a donné des chaussettes coupées là où le
     * kit prévoit des montantes, parce qu'il y en avait dans l'armoire. La ligne est servie —
     * elle sort des achats, on ne recommande pas ce qui est déjà aux pieds du joueur — et
     * c'est bien le carton ouvert qui décrémente, pas celui du kit.
     *
     * **Ce n'est pas un écoulement.** Rien n'est déclaré, aucune autre ligne n'en est changée,
     * et l'arbitrage ne proposera jamais cet article de lui-même : une exception constatée sur
     * une ligne ne doit pas devenir la règle de toute la saison. Sa sortie est celle de la
     * remise elle-même — « Annuler la remise » rend la ligne au kit.
     *
     * @throws \DomainException si la ligne est déjà remise
     */
    public function remettreAutreArticle(DotationBesoin $besoin, StockItem $article, ?User $user): void
    {
        // Une ligne préparée reste éligible : le sac avait bien été fait, c'est autre chose
        // qui est parti avec la personne. L'unité qu'il réservait retourne au pool à la
        // prochaine passe de l'allocateur, la ligne n'y étant plus comptée.
        if ($besoin->getStatut()->estRemis()) {
            throw new \DomainException('Cet article a déjà été remis. Annulez d\'abord la remise pour en remettre un autre.');
        }

        // L'article du kit repris dans le sélecteur : c'est une remise ordinaire, la ligne n'a
        // aucune exception à porter.
        if ($article->getId() === $besoin->getArticleServi()->getId()) {
            $this->marquerRemis($besoin, $user);

            return;
        }

        $besoin->setArticleRemis($article)->setTaille($this->tailleServie($besoin, $article));

        $this->marquerRemis($besoin, $user);
    }

    /**
     * Annule une remise : repasse le besoin à « à donner » et supprime le mouvement de sortie.
     *
     * « À donner » et non « préparé », même si la ligne en venait : le sac, on ne sait pas
     * s'il existe encore. Repartir de l'état le plus ouvert rend la main à l'automate et ne
     * pose aucun verrou que l'admin n'a pas demandé — un clic sur « Préparer » le remet.
     */
    public function annulerRemise(DotationBesoin $besoin): void
    {
        if (!$besoin->getStatut()->estRemis()) {
            return;
        }

        $mouvement = $besoin->getMouvementSortie();
        // L'article remis hors kit part avec la remise qu'il décrivait : la ligne redevient
        // celle du kit, et l'allocateur reprend la main dessus.
        $besoin->setStatut(DotationBesoinStatut::A_DONNER)->setMouvementSortie(null)->setArticleRemis(null);

        if ($mouvement !== null) {
            $this->em->remove($mouvement);
        }

        $this->em->flush();
    }

    /**
     * Fixe (ou réinitialise) à la main la taille d'un besoin.
     *
     * Une taille vide repasse en mode automatique : elle sera de nouveau déduite du dossier au
     * prochain recalcul. Si l'article a déjà été remis, le mouvement de stock est rejoué pour
     * que le stock réel suive le changement de taille.
     */
    public function changerTaille(DotationBesoin $besoin, ?string $taille, ?User $user = null): void
    {
        $taille = trim((string) $taille) ?: null;

        if ($besoin->getStatut()->estRemis() && $taille !== $besoin->getTaille()) {
            $this->rejouerMouvement($besoin, $taille, $user);
        }

        $besoin->setTaille($taille)->setTailleManuelle($taille !== null);

        $this->em->flush();
    }

    /**
     * Supprime l'ancien mouvement — ce qui restitue le stock de l'ancienne taille — et en crée
     * un nouveau à la taille voulue.
     */
    private function rejouerMouvement(DotationBesoin $besoin, ?string $taille, ?User $user): void
    {
        $ancien = $besoin->getMouvementSortie();
        $besoin->setMouvementSortie(null);

        if ($ancien !== null) {
            $this->em->remove($ancien);
        }

        $besoin->setMouvementSortie($this->sortirDuStock($besoin, $taille, $user));
    }

    /**
     * Taille à laquelle sortir un article qu'on remet à la place de celui du kit. La taille en
     * place est celle du carton prévu — « 43-46 » chez l'un, « 44 » chez l'autre : elle n'est
     * reprise que si le carton ouvert la décline, sinon on retraduit ce que la personne a
     * déclaré. Même règle que `DotationEcoulementAllocator::tailleServie()`, et il le faut :
     * une sortie rangée sous une déclinaison que l'article ne vend pas ne se retrouve plus en
     * stock.
     */
    private function tailleServie(DotationBesoin $besoin, StockItem $article): ?string
    {
        $taille = $besoin->getTaille();
        if ($taille !== null && $this->tailles->estAdmise($article, $taille)) {
            return $taille;
        }

        $personne = $besoin->getLicencie() ?? $besoin->getDirigeant();

        return $personne !== null ? $this->resolver->sizeFor($personne, $article) : null;
    }

    private function sortirDuStock(DotationBesoin $besoin, ?string $taille, ?User $user): StockMovement
    {
        // L'article servi, pas celui du kit : c'est le carton Nike qu'on ouvre quand la ligne
        // est couverte par un écoulement, et c'est donc lui qui doit décrémenter.
        $mouvement = $this->stockService->recordMovement(
            $besoin->getArticleServi(),
            $besoin->getQuantite(),
            StockMovementType::SORTIE,
            StockMovementSource::DOTATION,
            $user,
            'Dotation' . ($taille !== null ? ' — taille ' . $taille : ''),
            taille: $taille,
        );

        $mouvement->setLicencie($besoin->getLicencie());
        $mouvement->setDirigeant($besoin->getDirigeant());

        return $mouvement;
    }
}
