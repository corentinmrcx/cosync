<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\Entity\DotationBesoin;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Enum\DotationBesoinStatut;
use App\Enum\StockMovementSource;
use App\Enum\StockMovementType;
use App\Service\Stock\StockMovementService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Remise effective d'une dotation : ce qui sort réellement du stock, et les corrections
 * que l'admin apporte depuis le suivi (taille, flocage).
 */
final class DotationRemiseService
{
    public function __construct(
        private readonly StockMovementService $stockService,
        private readonly EntityManagerInterface $em,
    ) {}

    /** Marque un besoin comme remis : crée le mouvement de sortie et passe le statut à « donné ». */
    public function marquerRemis(DotationBesoin $besoin, ?User $user): void
    {
        if ($besoin->getStatut() === DotationBesoinStatut::DONNE) {
            return;
        }

        $besoin
            ->setStatut(DotationBesoinStatut::DONNE)
            ->setMouvementSortie($this->sortirDuStock($besoin, $besoin->getTaille(), $user));

        $this->em->flush();
    }

    /** Annule une remise : repasse le besoin à « à donner » et supprime le mouvement de sortie. */
    public function annulerRemise(DotationBesoin $besoin): void
    {
        if ($besoin->getStatut() !== DotationBesoinStatut::DONNE) {
            return;
        }

        $mouvement = $besoin->getMouvementSortie();
        $besoin->setStatut(DotationBesoinStatut::A_DONNER)->setMouvementSortie(null);

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

        if ($besoin->getStatut() === DotationBesoinStatut::DONNE && $taille !== $besoin->getTaille()) {
            $this->rejouerMouvement($besoin, $taille, $user);
        }

        $besoin->setTaille($taille)->setTailleManuelle($taille !== null);

        $this->em->flush();
    }

    /**
     * Corrige le texte de flocage depuis le suivi admin — pour rattraper une faute de frappe du
     * licencié avant la commande. Refusé une fois l'article remis : le vêtement est déjà floqué,
     * et le texte porté par le besoin est la trace de ce qui a réellement été donné.
     *
     * @throws \DomainException si le besoin est déjà marqué comme donné
     */
    public function changerPersonnalisation(DotationBesoin $besoin, ?string $texte): void
    {
        if ($besoin->getStatut() === DotationBesoinStatut::DONNE) {
            throw new \DomainException('Cet article a déjà été remis : son flocage ne peut plus être modifié.');
        }

        $normalise = $texte !== null ? trim((string) preg_replace('/\s+/u', ' ', $texte)) : '';
        $besoin->setPersonnalisation($normalise !== '' ? $normalise : null);

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

    private function sortirDuStock(DotationBesoin $besoin, ?string $taille, ?User $user): StockMovement
    {
        $mouvement = $this->stockService->recordMovement(
            $besoin->getStockItem(),
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
