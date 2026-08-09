<?php declare(strict_types=1);

namespace App\ArgumentResolver;

use App\Attribute\CurrentSeason;
use App\Entity\Season;
use App\Exception\AucuneSeasonException;
use App\Service\Saison\SeasonContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

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
