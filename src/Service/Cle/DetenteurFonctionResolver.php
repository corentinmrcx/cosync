<?php declare(strict_types=1);

namespace App\Service\Cle;

use App\Entity\Detenteur;
use App\Entity\Dirigeant;
use App\Enum\DirigeantRole;
use App\Service\Referentiel\FonctionPresenter;

/**
 * Ce qu'on écrit en face d'un détenteur de clés sur le récapitulatif remis à la mairie.
 *
 * La mairie demande la fonction de qui détient un trousseau. Trois sources répondent, et
 * l'ordre compte :
 *
 * 1. les {@see \App\Entity\Fonction} déclarées sur la fiche du dirigeant de la saison —
 *    la parole du club, celle du document remis au conseil d'administration ;
 * 2. la qualité du détenteur ({@see Detenteur::$qualite}) — « Mairie de Soudron »,
 *    « Entreprise Ménage+ » : une clé sort du club sans que personne soit à l'effectif ;
 * 3. à défaut, un repli **dérivé du rôle**, et surtout pas son libellé d'écran : le rôle
 *    `RESPONSABLE_FOOT` groupe les membres du bureau du foot, il ne titre personne. Écrire
 *    « Responsable foot » en face de quatre noms sur un courrier à la mairie annoncerait
 *    quatre responsables de section là où le club n'en a qu'un.
 *
 * Un repli n'est jamais vide non plus : une case blanche sur un document officiel se lit
 * comme un oubli, alors que « Dirigeant du club » est exact et suffit.
 */
final class DetenteurFonctionResolver
{
    public function __construct(
        private readonly FonctionPresenter $presenter,
    ) {}

    public function pour(Detenteur $detenteur, ?Dirigeant $dirigeant): string
    {
        if ($dirigeant !== null) {
            $declaree = $this->presenter->phrase($dirigeant);

            if ($declaree !== null) {
                return $declaree;
            }
        }

        $qualite = trim((string) $detenteur->getQualite());

        if ($qualite !== '') {
            return $qualite;
        }

        return $dirigeant !== null
            ? $this->repliDuRole($dirigeant)
            : 'Détenteur extérieur au club';
    }

    private function repliDuRole(Dirigeant $dirigeant): string
    {
        $equipe = $dirigeant->getTeam()?->getName();

        return match ($dirigeant->getRole()) {
            DirigeantRole::RESPONSABLE_FOOT => 'Membre du bureau du foot',
            DirigeantRole::RESPONSABLE_EQUIPE => $equipe !== null
                ? 'Responsable de l\'équipe ' . $equipe
                : 'Responsable d\'équipe',
            DirigeantRole::DIRIGEANT => 'Dirigeant du club',
        };
    }
}
