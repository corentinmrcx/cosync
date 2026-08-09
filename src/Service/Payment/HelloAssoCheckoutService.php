<?php declare(strict_types=1);

namespace App\Service\Payment;

use App\DTO\HelloAssoCheckoutIntent;
use App\Entity\Licencie;
use App\Service\Payment\CotisationResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ouverture d'une intention de paiement HelloAsso pour un licencié.
 *
 * L'horodatage de départ borne la réconciliation ultérieure : sans lui, la commande de
 * synchronisation ne saurait pas à partir de quand chercher l'encaissement.
 */
final class HelloAssoCheckoutService
{
    public function __construct(
        private readonly HelloAssoClient $client,
        private readonly CotisationResolver $cotisationResolver,
        private readonly EntityManagerInterface $em,
    ) {}

    /** @throws HelloAssoException si HelloAsso ne répond pas ou refuse l'intention */
    public function demarrer(Licencie $licencie, string $urlRetour, string $urlErreur): HelloAssoCheckoutIntent
    {
        $intent = $this->client->createCheckoutIntent(
            $licencie,
            $this->cotisationResolver->resolve($licencie),
            $urlRetour,
            $urlErreur,
        );

        $licencie->getDossierClub()
            ?->setHelloassoCheckoutIntentId($intent->id)
            ->setHelloassoCheckoutStartedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $intent;
    }
}
