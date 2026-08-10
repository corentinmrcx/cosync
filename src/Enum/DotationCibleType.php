<?php declare(strict_types=1);

namespace App\Enum;

/**
 * À qui un kit de dotation est attribué. `DEFAUT` est la cible de repli : elle s'applique
 * à toute personne qu'aucune autre affectation ne vise (cf. DotationAffectation::priorite()).
 */
enum DotationCibleType: string
{
    case CATEGORY = 'category';
    case TEAM = 'team';
    case LICENCIE = 'licencie';
    case DIRIGEANT = 'dirigeant';
    case ROLE = 'role';
    case DEFAUT = 'default';

    public function estDefaut(): bool
    {
        return $this === self::DEFAUT;
    }
}
