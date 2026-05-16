<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Enum\LicenceStatus;
use App\Repository\CategoryRepository;
use App\Repository\LicencieRepository;
use App\Repository\SeasonRepository;
use App\Repository\TeamRepository;
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
        SeasonRepository $seasonRepo,
        TeamRepository $teamRepo,
        CategoryRepository $categoryRepo,
    ): Response {
        $season = $seasonRepo->findActive();

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

        $search = trim((string) $request->query->get('search', ''));

        return $this->render('admin/licencies/list.html.twig', [
            'licencies'       => $licencieRepo->findWithFilters($season, $currentTeam, $currentCategory, $currentStatus, $search ?: null),
            'season'          => $season,
            'teams'           => $teamRepo->findBySeason($season),
            'categories'      => $categoryRepo->findAll(),
            'statuses'        => LicenceStatus::cases(),
            'currentTeam'     => $currentTeam,
            'currentCategory' => $currentCategory,
            'currentStatus'   => $currentStatus,
            'search'          => $search,
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
}
