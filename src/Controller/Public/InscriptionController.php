<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\LicencieRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/inscription', name: 'public_inscription_')]
class InscriptionController extends AbstractController
{
    #[Route('/{uuid}', name: 'show')]
    public function show(string $uuid, LicencieRepository $licencieRepo): Response
    {
        $licencie = $licencieRepo->findByUuid(Uuid::fromString($uuid));

        if ($licencie === null || !$licencie->isFormTokenValid()) {
            return $this->render('public/inscription/expired.html.twig');
        }

        return $this->render('public/inscription/form.html.twig', [
            'licencie' => $licencie,
        ]);
    }
}
