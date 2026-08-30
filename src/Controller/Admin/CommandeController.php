<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\Entity\Commande;
use App\Entity\CommandeLigne;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\Permission;
use App\Repository\CommandeRepository;
use App\Security\CsrfGuard;
use App\Service\Dotation\DotationEcoulementAllocator;
use App\Service\Pdf\BonCommandePdfService;
use App\Service\Stock\AchatService;
use App\Service\Stock\CommandeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/commandes', name: 'admin_commandes_')]
#[IsGranted(Permission::COMMANDE_LIRE->value)]
class CommandeController extends AbstractController
{
    public function __construct(
        private readonly CsrfGuard $csrf,
        private readonly AchatService $achatService,
        private readonly CommandeService $commandeService,
        private readonly CommandeRepository $commandeRepository,
        private readonly BonCommandePdfService $pdfService,
        private readonly DotationEcoulementAllocator $ecoulementAllocator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[CurrentSeason] Season $season): Response
    {
        // Ce qu'un stock en cours d'écoulement couvre déjà n'a pas à figurer sur un bon de
        // commande : l'arbitrage passe avant le calcul, pas après.
        $this->ecoulementAllocator->allouer($season);

        return $this->render('admin/commandes/index.html.twig', [
            'season' => $season,
            'aCommander' => $this->achatService->computeACommander($season),
            'commandes' => $this->commandeRepository->findBySeason($season),
        ]);
    }

    #[Route('/generer', name: 'generer', methods: ['POST'])]
    #[IsGranted(Permission::COMMANDE_GERER->value)]
    public function generer(Request $request, #[CurrentSeason] Season $season): Response
    {
        $this->csrf->valider('commande_generer', $request);

        // Le bon de commande fige ce qui sera acheté : l'arbitrage doit être à jour au moment
        // où il s'écrit, pas seulement au moment où l'écran a été affiché.
        $this->ecoulementAllocator->allouer($season);

        $bons = $this->commandeService->genererBons($season);

        if ($bons === []) {
            $this->addFlash('warning', 'Rien à commander pour le moment.');
        } else {
            $this->addFlash('success', sprintf('%d bon(s) de commande généré(s).', count($bons)));
        }

        return $this->redirectToRoute('admin_commandes_index');
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Commande $commande): Response
    {
        return $this->render('admin/commandes/show.html.twig', [
            'commande' => $commande,
        ]);
    }

    #[Route('/{id}/commander', name: 'commander', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::COMMANDE_GERER->value)]
    public function commander(Commande $commande, Request $request): Response
    {
        $this->csrf->valider('commande_commander_' . $commande->getId(), $request);

        $dateRaw = trim((string) $request->request->get('date_commande', ''));
        try {
            $date = $dateRaw !== '' ? new \DateTimeImmutable($dateRaw) : new \DateTimeImmutable();
        } catch (\Exception) {
            $date = new \DateTimeImmutable();
        }

        $this->commandeService->marquerCommandee($commande, $date);
        $this->addFlash('success', 'Commande marquée comme commandée.');

        return $this->redirectToRoute('admin_commandes_show', ['id' => $commande->getId()]);
    }

    #[Route('/lignes/{id}/recevoir', name: 'ligne_recevoir', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::COMMANDE_GERER->value)]
    public function ligneRecevoir(CommandeLigne $ligne, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->csrf->valider('commande_ligne_recevoir_' . $ligne->getId(), $request);

        $qty = (int) $request->request->get('quantite', 0);
        $this->commandeService->recevoirLigne($ligne, $qty, $user);
        $this->addFlash('success', 'Réception enregistrée.');

        return $this->redirectToRoute('admin_commandes_show', ['id' => $ligne->getCommande()->getId()]);
    }

    #[Route('/lignes/{id}/annuler-reception', name: 'ligne_annuler_reception', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::COMMANDE_GERER->value)]
    public function ligneAnnulerReception(CommandeLigne $ligne, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->csrf->valider('commande_ligne_annuler_' . $ligne->getId(), $request);

        $this->commandeService->annulerReception($ligne, $user);
        $this->addFlash('success', 'Réception annulée, stock recalculé.');

        return $this->redirectToRoute('admin_commandes_show', ['id' => $ligne->getCommande()->getId()]);
    }

    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pdf(Commande $commande): Response
    {
        $pdf = $this->pdfService->generate($commande);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="bon_commande_%d.pdf"', $commande->getId()),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted(Permission::COMMANDE_GERER->value)]
    public function delete(Commande $commande, Request $request): Response
    {
        $this->csrf->valider('commande_delete_' . $commande->getId(), $request);

        try {
            $this->commandeService->supprimerBrouillon($commande);
            $this->addFlash('success', 'Brouillon supprimé.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_commandes_index');
    }
}
