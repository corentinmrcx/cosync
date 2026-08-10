<?php declare(strict_types=1);

namespace App\ArgumentResolver;

use App\Attribute\CurrentSeason;
use App\Entity\Season;
use App\Exception\AucuneSeasonException;
use App\Service\Saison\SeasonContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Priorité au-dessus de l'EntityValueResolver de Doctrine (110), qui sinon passe
 * en premier et tente de charger la saison depuis le `{id}` de la route — celui
 * d'un tout autre objet. `#[CurrentSeason]` désigne la saison de travail de
 * l'admin, jamais un paramètre d'URL : ce résolveur doit donc trancher le premier.
 */
#[AutoconfigureTag('controller.argument_value_resolver', ['priority' => 150])]
final class CurrentSeasonResolver implements ValueResolverInterface
{
    public function __construct(
        private readonly SeasonContext $seasonContext,
    ) {}

    /** @return iterable<Season> */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if ($argument->getType() !== Season::class || $argument->getAttributes(CurrentSeason::class) === []) {
            return [];
        }

        $season = $this->seasonContext->getCurrentSeason();

        if ($season === null) {
            throw new AucuneSeasonException();
        }

        return [$season];
    }
}
