<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Licencie;
use App\Repository\LicencieRepository;
use App\Service\CotisationResolver;
use App\Service\Payment\HelloAssoClient;
use App\Service\Payment\HelloAssoException;
use App\Service\Payment\HelloAssoPaymentRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Paiement en ligne de la cotisation via HelloAsso.
 *
 * Le licencié quitte CoSync pour payer sur le domaine HelloAsso : aucune donnée bancaire
 * ne transite ni n'est stockée ici. Au retour, rien n'est cru sur parole — l'état du paiement
 * est relu auprès de l'API par HelloAssoPaymentRecorder.
 */
#[Route('/inscription/{uuid}/paiement', name: 'public_helloasso_', requirements: ['uuid' => Requirement::UUID])]
class PaiementController extends AbstractController
{
    public function __construct(
        private readonly LicencieRepository $licencieRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /** Depuis la page de confirmation : payer plus tard, ou réessayer après un abandon. */
    #[Route('/checkout', name: 'checkout', methods: ['POST'])]
    public function checkout(
        string $uuid,
        Request $request,
        HelloAssoClient $client,
        CotisationResolver $cotisationResolver,
        EntityManagerInterface $em,
    ): Response {
        $licencie = $this->findSubmittedLicencie($uuid);

        if ($licencie === null) {
            return $this->render('public/inscription/expired.html.twig');
        }

        if (!$this->isCsrfTokenValid('paiement_helloasso', $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');

            return $this->redirectToRoute('public_inscription_confirmation', ['uuid' => $uuid]);
        }

        return $this->startCheckout($licencie, $client, $cotisationResolver, $em);
    }

    /**
     * Étape intermédiaire après la soumission du formulaire avec paiement par carte.
     * En GET pour conserver le POST-redirect-GET : un rafraîchissement ne resoumet pas
     * l'inscription, il recrée simplement une intention de paiement.
     */
    #[Route('/demarrer', name: 'checkout_start', methods: ['GET'])]
    public function checkoutStart(
        string $uuid,
        HelloAssoClient $client,
        CotisationResolver $cotisationResolver,
        EntityManagerInterface $em,
    ): Response {
        $licencie = $this->findSubmittedLicencie($uuid);

        if ($licencie === null) {
            return $this->render('public/inscription/expired.html.twig');
        }

        return $this->startCheckout($licencie, $client, $cotisationResolver, $em);
    }

    /**
     * Retour depuis HelloAsso. On profite du passage pour relire l'état réel du paiement côté
     * serveur (même code que le webhook, idempotent), mais on n'annonce jamais l'encaissement au
     * licencié : l'affichage reste « en cours de validation » quel que soit le résultat. Le club
     * voit le paiement dans l'admin dès qu'il est réel, et le webhook reste le filet de sécurité
     * si le licencié ferme l'onglet trop tôt.
     */
    #[Route('/retour', name: 'return', methods: ['GET'])]
    public function retour(string $uuid, HelloAssoPaymentRecorder $recorder): Response
    {
        $licencie = $this->findSubmittedLicencie($uuid);

        if ($licencie === null) {
            return $this->render('public/inscription/expired.html.twig');
        }

        $intentId = $licencie->getDossierClub()?->getHelloassoCheckoutIntentId();

        if ($intentId !== null) {
            try {
                $recorder->recordFromCheckoutIntent($intentId);
            } catch (HelloAssoException $e) {
                // Sans réponse de HelloAsso on n'enregistre rien : le webhook ou la commande de
                // réconciliation rattraperont l'encaissement.
                $this->logger->error('HelloAsso : vérification du paiement impossible au retour du licencié : {message}', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $this->render('public/paiement/retour.html.twig', ['licencie' => $licencie]);
    }

    /** Paiement abandonné ou refusé. L'inscription, elle, est bien enregistrée. */
    #[Route('/erreur', name: 'error', methods: ['GET'])]
    public function erreur(string $uuid, CotisationResolver $cotisationResolver): Response
    {
        $licencie = $this->findSubmittedLicencie($uuid);

        if ($licencie === null) {
            return $this->render('public/inscription/expired.html.twig');
        }

        return $this->render('public/paiement/erreur.html.twig', [
            'licencie' => $licencie,
            'montant'  => $cotisationResolver->resolve($licencie),
        ]);
    }

    private function startCheckout(
        Licencie $licencie,
        HelloAssoClient $client,
        CotisationResolver $cotisationResolver,
        EntityManagerInterface $em,
    ): Response {
        $uuid = (string) $licencie->getUuid();

        try {
            $intent = $client->createCheckoutIntent(
                $licencie,
                $cotisationResolver->resolve($licencie),
                $this->generateUrl('public_helloasso_return', ['uuid' => $uuid], UrlGeneratorInterface::ABSOLUTE_URL),
                $this->generateUrl('public_helloasso_error', ['uuid' => $uuid], UrlGeneratorInterface::ABSOLUTE_URL),
            );
        } catch (HelloAssoException $e) {
            $this->logger->error('HelloAsso : création de l\'intention de paiement impossible : {message}', [
                'message' => $e->getMessage(),
            ]);
            $this->addFlash('error', "Le paiement en ligne est momentanément indisponible. Votre inscription est bien enregistrée, vous pouvez réessayer ou régler autrement.");

            return $this->redirectToRoute('public_inscription_confirmation', ['uuid' => $uuid]);
        }

        $licencie->getDossierClub()?->setHelloassoCheckoutIntentId($intent->id);
        $em->flush();

        return $this->redirect($intent->redirectUrl);
    }

    /** Le paiement en ligne suppose un dossier dont le formulaire a été soumis. */
    private function findSubmittedLicencie(string $uuid): ?Licencie
    {
        $licencie = $this->licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || $licencie->getDossierClub()?->getFormCompletedAt() === null) {
            return null;
        }

        return $licencie;
    }
}
