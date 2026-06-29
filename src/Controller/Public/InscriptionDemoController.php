<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Category;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Repository\SeasonRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Version de démonstration du formulaire public licencié.
 * Construit un licencié transient (jamais persisté) et n'enregistre rien :
 * ni base de données, ni Drive. Sert à montrer le parcours aux membres de
 * l'association sans impacter l'application.
 */
class InscriptionDemoController extends AbstractController
{
    #[Route('/inscription-demo', name: 'public_inscription_demo_show', methods: ['GET'])]
    public function show(SeasonRepository $seasonRepo): Response
    {
        // Saison réelle (lecture seule) pour afficher le vrai règlement et le bon tarif
        $season = $seasonRepo->findMostRecent();

        if ($season === null) {
            $season = (new Season())
                ->setLabel('Saison démo')
                ->setCotisationDefaut(85);
        }

        // Catégorie jeune → le parcours montre toutes les étapes (autorisations, attestation)
        $category = (new Category())
            ->setCode('U13')
            ->setLabel('U13')
            ->setIsEcoleFoot(true);

        $licencie = (new Licencie())
            ->setNom('DÉMO')
            ->setPrenom('Alex')
            ->setCategory($category)
            ->setSeason($season);

        $montant = $season->getCotisationDefaut();

        return $this->render('public/inscription/form.html.twig', [
            'licencie' => $licencie,
            'montant'  => $montant,
            'demo'     => true,
        ]);
    }

    #[Route('/inscription-demo/confirmation', name: 'public_inscription_demo_confirmation', methods: ['GET'])]
    public function confirmation(): Response
    {
        return $this->render('public/inscription/demo_confirmation.html.twig');
    }
}
