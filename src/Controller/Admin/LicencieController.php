<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\LicenceStatus;
use App\Repository\CategoryRepository;
use App\Repository\LicencieRepository;
use App\Repository\TeamRepository;
use App\Service\Mail\InscriptionLinkService;
use App\Service\SeasonContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/licencies', name: 'admin_licencies_')]
class LicencieController extends AbstractController
{
    #[Route('', name: 'list')]
    public function list(
        Request $request,
        LicencieRepository $licencieRepo,
        SeasonContext $seasonContext,
        TeamRepository $teamRepo,
        CategoryRepository $categoryRepo,
    ): Response {
        $season = $seasonContext->getCurrentSeason();

        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_list');
        }

        $currentTeam     = null;
        $currentCategory = null;
        $currentStatus   = null;

        if ($request->query->has('team') && $request->query->get('team') !== '') {
            $currentTeam = $teamRepo->find((int) $request->query->get('team'));
        }
        if ($request->query->has('category') && $request->query->get('category') !== '') {
            $currentCategory = $categoryRepo->find((int) $request->query->get('category'));
        }
        if ($request->query->has('status') && $request->query->get('status') !== '') {
            $currentStatus = LicenceStatus::tryFrom($request->query->get('status'));
        }

        $search  = trim((string) $request->query->get('search', ''));
        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        $total = $licencieRepo->countWithFilters($season, $currentTeam, $currentCategory, $currentStatus, $search ?: null);
        $pages = (int) ceil($total / $perPage);

        return $this->render('admin/licencies/list.html.twig', [
            'licencies'       => $licencieRepo->findWithFilters($season, $currentTeam, $currentCategory, $currentStatus, $search ?: null, $perPage, $offset),
            'season'          => $season,
            'teams'           => $teamRepo->findBySeason($season),
            'categories'      => $categoryRepo->findAll(),
            'statuses'        => LicenceStatus::cases(),
            'currentTeam'     => $currentTeam,
            'currentCategory' => $currentCategory,
            'currentStatus'   => $currentStatus,
            'search'          => $search,
            'total'           => $total,
            'page'            => $page,
            'pages'           => $pages,
        ]);
    }

    #[Route('/{uuid}', name: 'show')]
    public function show(string $uuid, LicencieRepository $licencieRepo): Response
    {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null) {
            throw $this->createNotFoundException('Licencié introuvable.');
        }

        return $this->render('admin/licencies/show.html.twig', [
            'licencie' => $licencie,
        ]);
    }

    #[Route('/{uuid}/send-link', name: 'send_link', methods: ['POST'])]
    public function sendLink(string $uuid, LicencieRepository $licencieRepo, InscriptionLinkService $inscriptionLinkService, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('send_link_' . $uuid, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null) {
            throw $this->createNotFoundException('Licencié introuvable.');
        }

        if ($licencie->getEmail() === null) {
            $this->addFlash('error', 'Ce licencié n\'a pas d\'adresse email renseignée.');
            return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid]);
        }

        try {
            $inscriptionLinkService->send($licencie);
            $this->addFlash('success', 'Lien d\'inscription envoyé à ' . $licencie->getEmail() . '.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur lors de l\'envoi : ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_licencies_show', ['uuid' => $uuid]);
    }
}
