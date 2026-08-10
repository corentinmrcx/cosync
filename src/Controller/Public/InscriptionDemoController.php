<?php declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Category;
use App\Entity\Licencie;
use App\Entity\Season;
use App\Enum\DocumentCible;
use App\Repository\DocumentSignableRepository;
use App\Repository\SeasonRepository;
use App\Service\Inscription\InscriptionFormConfig;
use App\Service\Payment\CotisationResolver;
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
    public function __construct(
        private readonly CotisationResolver $cotisationResolver,
        private readonly InscriptionFormConfig $formConfig,
        private readonly SeasonRepository $seasonRepo,
        private readonly DocumentSignableRepository $documentRepo,
    ) {}

    #[Route('/inscription-demo', name: 'public_inscription_demo_show', methods: ['GET'])]
    public function show(): Response
    {
        // Saison réelle (lecture seule) pour afficher les vrais documents et le bon tarif
        $season = $this->seasonRepo->findMostRecent();

        // Le licencié de démo n'existe pas en base : aucune signature ne peut lui être
        // rattachée, tous les documents actifs de la saison sont donc à signer.
        $documents = $season === null
            ? []
            : $this->documentRepo->findActifsByCible($season, DocumentCible::LICENCIE);

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
            'montant' => $montant,
            'libelleVirement' => $this->cotisationResolver->libelleVirement($licencie),
            'documents' => $documents,
            'demo' => true,
            'config' => $this->formConfig->pourDemo(
                $licencie,
                $documents,
                $this->generateUrl('public_inscription_demo_confirmation'),
            ),
        ]);
    }

    #[Route('/inscription-demo/confirmation', name: 'public_inscription_demo_confirmation', methods: ['GET'])]
    public function confirmation(): Response
    {
        return $this->render('public/inscription/demo_confirmation.html.twig');
    }
}
