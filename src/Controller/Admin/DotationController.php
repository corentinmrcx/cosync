<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\DotationAffectation;
use App\Entity\DotationBesoin;
use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Repository\CategoryRepository;
use App\Repository\DirigeantRepository;
use App\Repository\DotationAffectationRepository;
use App\Repository\DotationBesoinRepository;
use App\Repository\DotationModeleLigneRepository;
use App\Repository\DotationModeleRepository;
use App\Repository\LicencieRepository;
use App\Repository\StockItemRepository;
use App\Repository\TeamRepository;
use App\Service\SeasonContext;
use App\Service\Stock\DotationBesoinService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/stock/dotations', name: 'admin_stock_dotations_')]
class DotationController extends AbstractController
{
    public function __construct(
        private readonly SeasonContext $seasonContext,
        private readonly DotationModeleRepository $modeleRepository,
        private readonly DotationModeleLigneRepository $ligneRepository,
        private readonly DotationAffectationRepository $affectationRepository,
        private readonly StockItemRepository $itemRepository,
        private readonly TeamRepository $teamRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly LicencieRepository $licencieRepository,
        private readonly DirigeantRepository $dirigeantRepository,
        private readonly DotationBesoinRepository $besoinRepository,
        private readonly DotationBesoinService $besoinService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            $this->addFlash('warning', 'Créez une saison avant de gérer les dotations.');
            return $this->redirectToRoute('admin_seasons_new');
        }

        return $this->render('admin/stock/dotations/index.html.twig', [
            'season'       => $season,
            'modeles'      => $this->modeleRepository->findBySeason($season),
            'affectations' => $this->affectationRepository->findBySeason($season),
            'categories'   => $this->categoryRepository->findBy([], ['minYear' => 'ASC']),
            'teams'        => $this->teamRepository->findBySeason($season),
            'licencies'    => $this->licencieRepository->findValidatedBySeason($season),
            'dirigeants'   => $this->dirigeantRepository->findBySeason($season),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['POST'])]
    public function modeleNew(Request $request): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }
        if (!$this->isCsrfTokenValid('dotation_modele_new', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_index');
        }

        $nom = trim((string) $request->request->get('nom', ''));
        if ($nom === '') {
            $this->addFlash('error', 'Le nom du modèle est obligatoire.');
            return $this->redirectToRoute('admin_stock_dotations_index');
        }

        $modele = (new DotationModele())->setSeason($season)->setNom($nom);
        $this->em->persist($modele);
        $this->em->flush();
        $this->addFlash('success', sprintf('Modèle « %s » créé. Ajoutez ses articles.', $nom));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function modeleEdit(DotationModele $modele, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('dotation_modele_edit_' . $modele->getId(), $request->request->get('_token'))) {
                $this->addFlash('error', 'Token CSRF invalide.');
                return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
            }
            $nom = trim((string) $request->request->get('nom', ''));
            if ($nom !== '') {
                $modele->setNom($nom);
            }
            $modele->setActif($request->request->get('actif') === '1');
            $this->em->flush();
            $this->addFlash('success', 'Modèle mis à jour.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        return $this->render('admin/stock/dotations/form.html.twig', [
            'modele'   => $modele,
            'articles' => $this->itemRepository->findAllOrdered(),
        ]);
    }

    #[Route('/{id}/supprimer', name: 'delete', methods: ['POST'])]
    public function modeleDelete(DotationModele $modele, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('dotation_modele_delete_' . $modele->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_index');
        }
        $this->em->remove($modele);
        $this->em->flush();
        $this->addFlash('success', 'Modèle supprimé.');

        return $this->redirectToRoute('admin_stock_dotations_index');
    }

    #[Route('/{id}/lignes', name: 'ligne_add', methods: ['POST'])]
    public function ligneAdd(DotationModele $modele, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('dotation_ligne_add_' . $modele->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        $item = $this->itemRepository->find((int) $request->request->get('stock_item_id'));
        if ($item === null) {
            $this->addFlash('error', 'Article introuvable.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        $ligne = (new DotationModeleLigne())
            ->setStockItem($item)
            ->setQuantite(max(1, (int) $request->request->get('quantite', 1)))
            ->setObligatoire($request->request->get('obligatoire') === '1')
            ->setGroupeChoix(trim((string) $request->request->get('groupe_choix', '')) ?: null);

        $modele->addLigne($ligne);
        $this->em->persist($ligne);
        $this->em->flush();
        $this->addFlash('success', sprintf('« %s » ajouté au modèle.', $item->getNom()));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
    }

    #[Route('/lignes/{id}/supprimer', name: 'ligne_delete', methods: ['POST'])]
    public function ligneDelete(DotationModeleLigne $ligne, Request $request): Response
    {
        $modeleId = $ligne->getModele()->getId();
        if (!$this->isCsrfTokenValid('dotation_ligne_delete_' . $ligne->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modeleId]);
        }
        $this->em->remove($ligne);
        $this->em->flush();
        $this->addFlash('success', 'Ligne retirée.');

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modeleId]);
    }

    #[Route('/affectations', name: 'affectation_new', methods: ['POST'])]
    public function affectationNew(Request $request): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }
        if (!$this->isCsrfTokenValid('dotation_affectation_new', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_index');
        }

        $modele = $this->modeleRepository->find((int) $request->request->get('modele_id'));
        if ($modele === null) {
            $this->addFlash('error', 'Modèle introuvable.');
            return $this->redirectToRoute('admin_stock_dotations_index');
        }

        $affectation = (new DotationAffectation())->setSeason($season)->setModele($modele);
        $cible = $request->request->get('cible_type');
        $cibleId = $request->request->get('cible_id');

        switch ($cible) {
            case 'category':
                $affectation->setCategory($this->categoryRepository->find((int) $cibleId));
                break;
            case 'team':
                $affectation->setTeam($this->teamRepository->find((int) $cibleId));
                break;
            case 'licencie':
                $affectation->setLicencie($cibleId ? $this->licencieRepository->findByUuid(Uuid::fromString($cibleId)) : null);
                break;
            case 'dirigeant':
                $affectation->setDirigeant($cibleId ? $this->dirigeantRepository->findByUuid(Uuid::fromString($cibleId)) : null);
                break;
            case 'default':
            default:
                // aucune cible → affectation par défaut
                break;
        }

        if ($cible !== 'default' && $affectation->priorite() === 0) {
            $this->addFlash('error', 'Cible invalide pour cette affectation.');
            return $this->redirectToRoute('admin_stock_dotations_index');
        }

        $this->em->persist($affectation);
        $this->em->flush();
        $this->addFlash('success', sprintf('Modèle « %s » affecté à : %s.', $modele->getNom(), $affectation->cibleLabel()));

        return $this->redirectToRoute('admin_stock_dotations_index');
    }

    #[Route('/affectations/{id}/supprimer', name: 'affectation_delete', methods: ['POST'])]
    public function affectationDelete(DotationAffectation $affectation, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('dotation_affectation_delete_' . $affectation->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_index');
        }
        $this->em->remove($affectation);
        $this->em->flush();
        $this->addFlash('success', 'Affectation supprimée.');

        return $this->redirectToRoute('admin_stock_dotations_index');
    }

    #[Route('/suivi', name: 'suivi', methods: ['GET'])]
    public function suivi(): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        /** @var array<string, DotationBesoin[]> $groupes */
        $groupes = [];
        foreach ($this->besoinRepository->findBySeason($season) as $besoin) {
            $groupes[$besoin->getTeamName() ?? 'Sans équipe'][] = $besoin;
        }
        ksort($groupes);

        return $this->render('admin/stock/dotations/suivi.html.twig', [
            'season'  => $season,
            'groupes' => $groupes,
        ]);
    }

    #[Route('/recalculer', name: 'recalculer', methods: ['POST'])]
    public function recalculer(Request $request): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }
        if (!$this->isCsrfTokenValid('dotation_recalculer', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_suivi');
        }

        $count = $this->besoinService->recomputeAll($season);
        $this->addFlash('success', sprintf('Besoins recalculés pour %d personne%s.', $count, $count > 1 ? 's' : ''));

        return $this->redirectToRoute('admin_stock_dotations_suivi');
    }

    #[Route('/besoins/{id}/remise', name: 'besoin_remise', methods: ['POST'])]
    public function besoinRemise(DotationBesoin $besoin, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('dotation_besoin_remise_' . $besoin->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_suivi');
        }

        $this->besoinService->markGiven($besoin, $this->getUser());
        $this->addFlash('success', sprintf('Dotation remise à %s.', $besoin->getNomPrenom()));

        return $this->redirectToRoute('admin_stock_dotations_suivi');
    }

    #[Route('/besoins/{id}/annuler', name: 'besoin_annuler', methods: ['POST'])]
    public function besoinAnnuler(DotationBesoin $besoin, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('dotation_besoin_annuler_' . $besoin->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_suivi');
        }

        $this->besoinService->cancelGiven($besoin);
        $this->addFlash('success', 'Remise annulée.');

        return $this->redirectToRoute('admin_stock_dotations_suivi');
    }
}
