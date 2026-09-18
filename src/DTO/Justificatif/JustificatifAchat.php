<?php declare(strict_types=1);

namespace App\DTO\Justificatif;

/**
 * Le justificatif d'une commande de dotations, prêt à rendre.
 *
 * Le document répond à une seule question, celle que pose la personne qui signe le devis :
 * **est-ce que ce qu'on achète va servir ?** Le club a déjà commandé 45 vestes pour en
 * distribuer 30, et les 15 autres dorment depuis. La règle est devenue : on commande ce qu'on
 * distribue, rien de plus — et {@see resteApresDistribution} est le chiffre qui le prouve.
 */
final class JustificatifAchat
{
    /**
     * @param list<JustificatifKit>         $kits
     * @param list<JustificatifEffectif>    $effectifs
     * @param list<JustificatifFournisseur> $fournisseurs
     */
    public function __construct(
        public readonly string $saisonLabel,
        public readonly \DateTimeImmutable $editeLe,
        public readonly array $kits,
        public readonly array $effectifs,
        public readonly array $fournisseurs,
        public readonly int $personnes,
        public readonly int $ilEnFaut,
        public readonly int $aCommander,
        public readonly int $dejaRemis,
        public readonly int $resteApresDistribution,
    ) {}

    /** Ce que le club n'achète pas parce qu'il l'a déjà. */
    public function onEnA(): int
    {
        return $this->ilEnFaut - $this->aCommander;
    }

    public function riendACommander(): bool
    {
        return $this->aCommander === 0;
    }

    /**
     * Ce que le club promet : tout ce qui est commandé part chez un licencié.
     *
     * Faux dès qu'il reste quelque chose en armoire après la distribution — du stock qui ne
     * trouve pas preneur, presque toujours une taille que personne ne porte. Le document le
     * dit alors au lieu de le taire : c'est ce stock-là qui a fait perdre la confiance.
     */
    public function toutSeraDistribue(): bool
    {
        return $this->resteApresDistribution === 0;
    }
}
