<?php declare(strict_types=1);

namespace App\Service\Dotation;

use App\DTO\DotationFlocageReglages;
use App\DTO\DotationSuiviGroupe;
use App\Entity\DotationBesoin;
use App\Enum\DotationBesoinStatut;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le texte à floquer d'un besoin : ce que le kit en attend, et sa saisie par l'admin.
 *
 * Le flocage vient normalement du formulaire du licencié. Deux situations le laissent vide sans
 * que personne ne se soit trompé : un kit créé après la validation d'une licence — le dossier
 * ne porte alors aucune réponse — et l'incident qui a empêché la personne de répondre. Le club
 * doit pouvoir renseigner le texte lui-même plutôt que de le saisir en base.
 *
 * D'où le verrou `personnalisationManuelle`, jumeau de celui de la taille : une fois le texte
 * saisi ici, le recalcul ne le remplace plus par celui — absent — du dossier. Vider le champ
 * relâche le verrou et rend la ligne au dossier.
 */
final class DotationFlocageService
{
    /** Libellé affiché quand le kit n'en fixe aucun — même repli que le formulaire public. */
    private const LABEL_DEFAUT = 'Texte à personnaliser';

    public function __construct(
        private readonly DotationResolver $resolver,
        private readonly DotationModeleService $modeles,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Réglages de flocage de ce besoin, ou null si son article ne se floque pas.
     *
     * Se lit sur le kit et non sur le besoin : c'est la seule façon de distinguer « floqué, mais
     * texte pas encore saisi » de « pas floqué du tout ». Le besoin, lui, porte null dans les
     * deux cas.
     */
    public function reglagesPour(DotationBesoin $besoin): ?DotationFlocageReglages
    {
        $personne = $besoin->getLicencie() ?? $besoin->getDirigeant();
        if ($personne === null) {
            return null;
        }

        $ligne = $this->resolver->ligneRetenue($personne, $besoin->getGroupeChoix(), $besoin->getStockItem());
        if ($ligne === null || !$ligne->isPersonnalisationRequise()) {
            return null;
        }

        return new DotationFlocageReglages(
            $ligne->getPersonnalisationLabel() ?? self::LABEL_DEFAUT,
            $this->modeles->maxLengthFor($ligne),
        );
    }

    /**
     * Réglages indexés par id de besoin — le suivi en a besoin pour toutes ses lignes d'un coup.
     *
     * @param  DotationSuiviGroupe[] $groupes
     * @return array<int, DotationFlocageReglages>
     */
    public function reglagesParBesoin(array $groupes): array
    {
        $out = [];

        foreach ($groupes as $groupe) {
            foreach ($groupe->besoins as $besoin) {
                $reglages = $this->reglagesPour($besoin);

                if ($reglages !== null) {
                    $out[$besoin->getId()] = $reglages;
                }
            }
        }

        return $out;
    }

    /**
     * Fixe (ou efface) à la main le texte à floquer d'un besoin.
     *
     * Refusé une fois l'article remis : le vêtement est déjà floqué, et le texte porté par le
     * besoin est la trace de ce qui a réellement été donné.
     *
     * @throws \DomainException si le besoin est déjà donné, si son article ne se floque pas,
     *                          ou si le texte dépasse la longueur permise par le kit
     */
    public function changer(DotationBesoin $besoin, ?string $texte): void
    {
        if ($besoin->getStatut() === DotationBesoinStatut::DONNE) {
            throw new \DomainException('Cet article a déjà été remis : son flocage ne peut plus être modifié.');
        }

        $reglages = $this->reglagesPour($besoin);
        if ($reglages === null) {
            throw new \DomainException('Cet article ne se floque pas : il n\'attend aucun texte.');
        }

        $normalise = trim((string) preg_replace('/\s+/u', ' ', (string) $texte));

        if (mb_strlen($normalise) > $reglages->maxLength) {
            throw new \DomainException(sprintf('Le texte à floquer ne peut pas dépasser %d caractères.', $reglages->maxLength));
        }

        // Un texte vide rend la ligne au dossier : elle redeviendra automatique au prochain
        // recalcul, exactement comme une taille effacée.
        $besoin
            ->setPersonnalisation($normalise !== '' ? $normalise : null)
            ->setPersonnalisationManuelle($normalise !== '');

        $this->em->flush();
    }
}
