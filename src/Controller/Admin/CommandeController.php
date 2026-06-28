<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Commande;
use App\Entity\CommandeLigne;
use App\Enum\CommandeStatut;
use App\Repository\CommandeRepository;
use App\Service\Pdf\BonCommandePdfService;
use App\Service\SeasonContext;
use App\Service\Stock\AchatService;
use App\Service\Stock\CommandeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/stock/commandes', name: 'admin_stock_commandes_')]
class CommandeController extends AbstractController
{
    public function __construct(
        private readonly SeasonContext $seasonContext,
        private readonly AchatService $achatService,
        private readonly CommandeService $commandeService,
        private readonly CommandeRepository $commandeRepository,
        private readonly BonCommandePdfService $pdfService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            $this->addFlash('warning', 'Créez une saison avant de gérer les commandes.');
            return $this->redirectToRoute('admin_seasons_new');
        }

        return $this->render('admin/stock/commandes/index.html.twig', [
            'season'      => $season,
            'aCommander'  => $this->achatService->computeACommander($season),
            'commandes'   => $this->commandeRepository->findBySeason($season),
        ]);
    }

    #[Route('/generer', name: 'generer', methods: ['POST'])]
    public function generer(Request $request): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }
        if (!$this->isCsrfTokenValid('commande_generer', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_commandes_index');
        }

        $bons = $this->commandeService->genererBons($season);

        if ($bons === []) {
            $this->addFlash('warning', 'Rien à commander pour le moment.');
        } else {
            $this->addFlash('success', sprintf('%d bon(s) de commande généré(s).', count($bons)));
        }

        return $this->redirectToRoute('admin_stock_commandes_index');
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Commande $commande): Response
    {
        return $this->render('admin/stock/commandes/show.html.twig', [
            'commande' => $commande,
        ]);
    }

    #[Route('/{id}/commander', name: 'commander', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function commander(Commande $commande, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('commande_commander_' . $commande->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_commandes_show', ['id' => $commande->getId()]);
        }

        $dateRaw = trim((string) $request->request->get('date_commande', ''));
        try {
            $date = $dateRaw !== '' ? new \DateTimeImmutable($dateRaw) : new \DateTimeImmutable();
        } catch (\Exception) {
            $date = new \DateTimeImmutable();
        }

        $this->commandeService->marquerCommandee($commande, $date);
        $this->addFlash('success', 'Commande marquée comme commandée.');

        return $this->redirectToRoute('admin_stock_commandes_show', ['id' => $commande->getId()]);
    }

    #[Route('/lignes/{id}/recevoir', name: 'ligne_recevoir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ligneRecevoir(CommandeLigne $ligne, Request $request): Response
    {
        $commandeId = $ligne->getCommande()->getId();
        if (!$this->isCsrfTokenValid('commande_ligne_recevoir_' . $ligne->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_commandes_show', ['id' => $commandeId]);
        }

        $qty = (int) $request->request->get('quantite', 0);
        $this->commandeService->recevoirLigne($ligne, $qty, $this->getUser());
        $this->addFlash('success', 'Réception enregistrée.');

        return $this->redirectToRoute('admin_stock_commandes_show', ['id' => $commandeId]);
    }

    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function pdf(Commande $commande): Response
    {
        $pdf = $this->pdfService->generate($commande);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="bon_commande_%d.pdf"', $commande->getId()),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Commande $commande, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('commande_delete_' . $commande->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_commandes_index');
        }

        // On ne supprime qu'un brouillon (une commande passée a généré des mouvements)
        if ($commande->getStatut() !== CommandeStatut::BROUILLON) {
            $this->addFlash('error', 'Seul un brouillon peut être supprimé.');
            return $this->redirectToRoute('admin_stock_commandes_index');
        }

        $this->em->remove($commande);
        $this->em->flush();
        $this->addFlash('success', 'Brouillon supprimé.');

        return $this->redirectToRoute('admin_stock_commandes_index');
    }
}
