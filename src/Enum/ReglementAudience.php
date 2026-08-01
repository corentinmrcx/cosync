<?php declare(strict_types=1);

namespace App\Enum;

use App\Entity\Season;

/**
 * Destinataire d'un règlement intérieur.
 *
 * Le club fait signer deux documents distincts : celui des joueurs, dans le
 * parcours d'inscription, et celui des dirigeants, dans le parcours dirigeant.
 * Le formulaire public, le PDF et l'écran d'édition admin sont mutualisés —
 * cet enum porte tout ce qui les différencie, pour éviter d'éparpiller des
 * conditions dans les templates et les services.
 */
enum ReglementAudience: string
{
    case LICENCIE = 'licencie';
    case DIRIGEANT = 'dirigeant';

    /** Titre du document, affiché en tête du formulaire public et du PDF */
    public function documentTitle(): string
    {
        return match ($this) {
            self::LICENCIE  => 'Règlement intérieur',
            self::DIRIGEANT => 'Règlement intérieur des dirigeants',
        };
    }

    /** Désignation du document dans une phrase (« …avoir lu et accepté le … ») */
    public function documentLabel(): string
    {
        return match ($this) {
            self::LICENCIE  => 'règlement intérieur du Foyer de Soudron',
            self::DIRIGEANT => 'règlement intérieur des dirigeants du Foyer de Soudron',
        };
    }

    /** Texte du règlement porté par la saison pour ce destinataire */
    public function textOf(Season $season): ?string
    {
        return match ($this) {
            self::LICENCIE  => $season->getReglementText(),
            self::DIRIGEANT => $season->getReglementDirigeantText(),
        };
    }

    /**
     * Suffixe du PDF généré dans var/pdfs/. Distinct pour qu'un dirigeant-joueur,
     * qui signe les deux règlements, n'écrase pas un document avec l'autre.
     */
    public function fileSuffix(): string
    {
        return match ($this) {
            self::LICENCIE  => '_reglement',
            self::DIRIGEANT => '_reglement_dirigeant',
        };
    }
}
