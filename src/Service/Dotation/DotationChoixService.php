<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\Entity\DotationBesoin;
use App\Entity\Licencie;
use App\Entity\StockItem;
use App\Enum\DotationBesoinStatut;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Correction par l'admin de l'option retenue dans un groupe de choix.
 *
 * Deux situations la rendent nécessaire, et aucune n'est marginale :
 * un kit créé après que des licences ont été validées — les dossiers ne portent alors
 * aucune réponse, et le résolveur retient par repli la première option pour tout le
 * monde — et le licencié qui s'est trompé et rappelle le club.
 *
 * La correction s'écrit dans le dossier, pas sur le besoin : c'est le dossier que lit
 * DotationResolver. Corriger le besoin seul serait effacé au recalcul suivant, et le
 * « à commander » continuerait de compter l'ancienne option.
 */
final class DotationChoixService
{
    public function __construct(
        private readonly DotationResolver $resolver,
        private readonly DotationBesoinSynchronizer $synchronizer,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @throws \DomainException si l'article n'est pas une option de ce choix, si la personne
     *                          n'a pas de dossier, ou si l'article a déjà été remis
     */
    public function corriger(DotationBesoin $besoin, StockItem $option): void
    {
        $groupe = $besoin->getGroupeChoix();
        if ($groupe === null) {
            throw new \DomainException('Cet article n\'appartient à aucun choix : il n\'y a rien à corriger.');
        }

        if ($besoin->getStatut() === DotationBesoinStatut::DONNE) {
            throw new \DomainException('Cet article a déjà été remis. Annulez d\'abord la remise pour changer l\'option.');
        }

        $licencie = $besoin->getLicencie();
        if (!$licencie instanceof Licencie) {
            throw new \DomainException('Seul le choix d\'un licencié se corrige : un dirigeant n\'a pas de dossier d\'inscription.');
        }

        $dossier = $licencie->getDossierClub();
        if ($dossier === null) {
            throw new \DomainException('Ce licencié n\'a pas encore de dossier : son choix ne peut pas être enregistré.');
        }

        if (!$this->estUneOption($besoin, $option, $groupe)) {
            throw new \DomainException('Cet article ne fait pas partie des options de ce choix.');
        }

        $choix = $dossier->getDotationChoix() ?? [];
        $choix[$groupe] = $option->getId();
        $dossier->setDotationChoix($choix);
        $this->em->flush();

        // Réaligne le besoin — et lui seul — sur le choix qui vient d'être enregistré.
        $this->synchronizer->recomputeForLicencie($licencie);
    }

    /**
     * Options réellement proposées à cette personne pour ce groupe, éligibilité comprise :
     * une option réservée aux nouveaux ne doit pas pouvoir être imposée à un renouvellement.
     *
     * @return StockItem[]
     */
    public function optionsDisponibles(DotationBesoin $besoin): array
    {
        $personne = $besoin->getLicencie() ?? $besoin->getDirigeant();
        $groupe = $besoin->getGroupeChoix();

        if ($personne === null || $groupe === null) {
            return [];
        }

        foreach ($this->resolver->getChoiceGroups($personne) as $candidat) {
            if ($candidat['groupe'] === $groupe) {
                return array_map(static fn ($ligne): StockItem => $ligne->getStockItem(), $candidat['options']);
            }
        }

        return [];
    }

    /**
     * Options proposées, indexées par id de besoin — le suivi en a besoin pour toutes ses
     * lignes d'un coup.
     *
     * @param  \App\DTO\DotationSuiviGroupe[] $groupes
     * @return array<int, StockItem[]>
     */
    public function optionsParBesoin(array $groupes): array
    {
        $out = [];

        foreach ($groupes as $groupe) {
            foreach ($groupe->besoins as $besoin) {
                if ($besoin->getGroupeChoix() !== null) {
                    $out[$besoin->getId()] = $this->optionsDisponibles($besoin);
                }
            }
        }

        return $out;
    }

    private function estUneOption(DotationBesoin $besoin, StockItem $option, string $groupe): bool
    {
        foreach ($this->optionsDisponibles($besoin) as $possible) {
            if ($possible->getId() === $option->getId()) {
                return true;
            }
        }

        return false;
    }
}
