<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Import\ImportService;
use App\Service\SeasonContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/import', name: 'admin_import_')]
class ImportController extends AbstractController
{
    public function __construct(
        private readonly SeasonContext $seasonContext,
        private readonly ImportService $importService,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        if ($this->seasonContext->getCurrentSeason() === null) {
            $this->addFlash('warning', 'Créez une saison avant de pouvoir importer des licenciés.');

            return $this->redirectToRoute('admin_seasons_new');
        }

        return $this->render('admin/import/index.html.twig', [
            'currentSeason' => $this->seasonContext->getCurrentSeason(),
        ]);
    }

    #[Route('', name: 'process', methods: ['POST'])]
    public function process(Request $request): Response
    {
        $season = $this->seasonContext->getCurrentSeason();

        if ($season === null) {
            $this->addFlash('error', 'Aucune saison sélectionnée. Créez et activez une saison d\'abord.');

            return $this->redirectToRoute('admin_import_index');
        }

        $file = $request->files->get('xlsx');

        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Aucun fichier reçu.');

            return $this->redirectToRoute('admin_import_index');
        }

        if ($file->getClientOriginalExtension() !== 'xlsx') {
            $this->addFlash('error', 'Le fichier doit être au format .xlsx');

            return $this->redirectToRoute('admin_import_index');
        }

        try {
            $result = $this->importService->importFromXlsx($file, $season);
        } catch (\Throwable) {
            $this->addFlash('error', 'Une erreur inattendue est survenue pendant l\'import. Veuillez réessayer.');

            return $this->redirectToRoute('admin_import_index');
        }

        return $this->render('admin/import/result.html.twig', ['result' => $result]);
    }
}
