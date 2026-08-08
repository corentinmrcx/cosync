<?php declare(strict_types=1);

namespace App\EventListener;

use App\Exception\AucuneSeasonException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: 'kernel.exception')]
final class AucuneSeasonListener
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof AucuneSeasonException && !$exception->getPrevious() instanceof AucuneSeasonException) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_seasons_new')));
    }
}
