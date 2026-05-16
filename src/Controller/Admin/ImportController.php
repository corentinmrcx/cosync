<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Import\ImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/import', name: 'admin_import_')]
class ImportController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/import/index.html.twig');
    }

    #[Route('', name: 'process', methods: ['POST'])]
    public function process(Request $request, ImportService $importService): Response
    {
        $file = $request->files->get('xlsx');

        if (!$file instanceof UploadedFile) {
            $this->addFlash('error', 'Aucun fichier reçu.');
            return $this->redirectToRoute('admin_import_index');
        }

        if ($file->getClientOriginalExtension() !== 'xlsx') {
            $this->addFlash('error', 'Le fichier doit être au format .xlsx');
            return $this->redirectToRoute('admin_import_index');
        }

        $result = $importService->importFromXlsx($file);

        return $this->render('admin/import/result.html.twig', ['result' => $result]);
    }
}
