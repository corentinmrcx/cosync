<?php declare(strict_types=1);

namespace App\Service\Stock;

use App\DTO\DotationLigneReglagesData;
use App\Entity\DotationModeleLigne;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestion des réglages fins d'une ligne de modèle de dotation (éligibilité, personnalisation).
 * La composition d'un kit — ajouter/retirer des articles — reste dans DotationController.
 */
final class DotationModeleService
{
    /** Longueur retenue quand l'admin n'en fixe aucune : un flocage tient rarement plus long. */
    public const PERSONNALISATION_MAX_DEFAUT = 15;

    /** Borne haute de sécurité — la colonne DotationBesoin::personnalisation fait 60. */
    private const PERSONNALISATION_MAX_ABSOLU = 60;

    public function __construct(private readonly EntityManagerInterface $em) {}

    public function updateReglages(DotationModeleLigne $ligne, DotationLigneReglagesData $data): void
    {
        $ligne->setEligibilite($data->eligibilite);
        $ligne->setPersonnalisationRequise($data->personnalisationRequise);

        if (!$data->personnalisationRequise) {
            // Une option qui ne demande plus de texte ne garde pas ses réglages orphelins.
            $ligne->setPersonnalisationLabel(null);
            $ligne->setPersonnalisationMaxLength(null);
            $this->em->flush();

            return;
        }

        $label = $data->personnalisationLabel !== null ? trim($data->personnalisationLabel) : '';
        $ligne->setPersonnalisationLabel($label !== '' ? $label : null);

        $max = $data->personnalisationMaxLength;
        $ligne->setPersonnalisationMaxLength(
            $max === null ? null : max(1, min($max, self::PERSONNALISATION_MAX_ABSOLU)),
        );

        $this->em->flush();
    }

    /** Longueur maximale effective d'un texte de personnalisation pour cette ligne. */
    public function maxLengthFor(DotationModeleLigne $ligne): int
    {
        return $ligne->getPersonnalisationMaxLength() ?? self::PERSONNALISATION_MAX_DEFAUT;
    }
}
