<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\DTO\DirigeantPublicFormData;
use App\Repository\DirigeantRepository;
use App\Service\DirigeantFormService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/dirigeant', name: 'public_dirigeant_')]
class DirigeantController extends AbstractController
{
    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $uuid, DirigeantRepository $dirigeantRepo): Response
    {
        $dirigeant = $dirigeantRepo->findByUuid(Uuid::fromString($uuid));

        if ($dirigeant === null) {
            return $this->render('public/dirigeant/expired.html.twig');
        }

        if ($dirigeant->getFormCompletedAt() !== null) {
            return $this->redirectToRoute('public_dirigeant_confirmation', ['uuid' => $uuid]);
        }

        if (!$dirigeant->isFormTokenValid()) {
            return $this->render('public/dirigeant/expired.html.twig');
        }

        return $this->render('public/dirigeant/form.html.twig', [
            'dirigeant' => $dirigeant,
        ]);
    }

    #[Route('/{uuid}', name: 'submit', methods: ['POST'])]
    public function submit(
        string $uuid,
        Request $request,
        DirigeantRepository $dirigeantRepo,
        DirigeantFormService $formService,
    ): Response {
        $dirigeant = $dirigeantRepo->findByUuid(Uuid::fromString($uuid));

        if ($dirigeant === null || !$dirigeant->isFormTokenValid()) {
            return $this->render('public/dirigeant/expired.html.twig');
        }

        if (!$this->isCsrfTokenValid('dirigeant_submit', $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expirée, veuillez réessayer.');
            return $this->redirectToRoute('public_dirigeant_show', ['uuid' => $uuid]);
        }

        $data = $this->buildFormData($request);

        if ($data === null) {
            $this->addFlash('error', 'Formulaire incomplet, veuillez remplir tous les champs.');
            return $this->redirectToRoute('public_dirigeant_show', ['uuid' => $uuid]);
        }

        $formService->submit($dirigeant, $data);

        return $this->redirectToRoute('public_dirigeant_confirmation', ['uuid' => $uuid]);
    }

    #[Route('/{uuid}/confirmation', name: 'confirmation', methods: ['GET'])]
    public function confirmation(string $uuid, DirigeantRepository $dirigeantRepo): Response
    {
        $dirigeant = $dirigeantRepo->findByUuid(Uuid::fromString($uuid));

        if ($dirigeant === null || $dirigeant->getFormCompletedAt() === null) {
            return $this->render('public/dirigeant/expired.html.twig');
        }

        return $this->render('public/dirigeant/confirmation.html.twig', [
            'dirigeant' => $dirigeant,
        ]);
    }

    private function buildFormData(Request $request): ?DirigeantPublicFormData
    {
        $tailleHaut = $request->request->get('taille_haut', '');
        $tailleBas  = $request->request->get('taille_bas', '');
        $pointure   = $request->request->get('pointure', '');

        if ($tailleHaut === '' || $tailleBas === '' || $pointure === '') {
            return null;
        }

        return new DirigeantPublicFormData(
            tailleHaut: $tailleHaut,
            tailleBas:  $tailleBas,
            pointure:   $pointure,
        );
    }
}
