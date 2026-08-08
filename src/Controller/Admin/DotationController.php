<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\DotationLigneReglagesData;
use App\Entity\DotationAffectation;
use App\Entity\DotationBesoin;
use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Enum\DirigeantRole;
use App\Enum\DotationEligibilite;
use App\Repository\CategoryRepository;
use App\Repository\DirigeantRepository;
use App\Repository\DotationAffectationRepository;
use App\Repository\DotationModeleLigneRepository;
use App\Repository\DotationModeleRepository;
use App\Repository\LicencieRepository;
use App\Repository\StockItemRepository;
use App\Repository\TeamRepository;
use App\Service\Form\DotationGroupeReglagesFactory;
use App\Service\SeasonContext;
use App\Service\Stock\DotationBesoinService;
use App\Service\Stock\DotationModelePreview;
use App\Service\Stock\DotationModeleService;
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
        private readonly DotationBesoinService $besoinService,
        private readonly DotationModeleService $modeleService,
        private readonly DotationModelePreview $preview,
        private readonly DotationGroupeReglagesFactory $reglagesFactory,
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

        // L'index ne fait que lister : contenu et destinataires d'un kit se règlent sur sa page.
        return $this->render('admin/stock/dotations/index.html.twig', [
            'season'       => $season,
            'modeles'      => $this->modeleRepository->findBySeason($season),
            'affectations' => $this->affectationRepository->findBySeason($season),
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

        $season = $modele->getSeason();
        // Composer un kit et dire qui le reçoit sont la même décision : les deux se font ici.
        $affectations = $this->affectationRepository->findByModele($modele);

        return $this->render('admin/stock/dotations/form.html.twig', [
            'modele'                    => $modele,
            'articles'                  => $this->itemRepository->findAllOrdered(),
            'eligibilites'              => DotationEligibilite::cases(),
            'personnalisationMaxDefaut' => DotationModeleService::PERSONNALISATION_MAX_DEFAUT,
            'affectations'              => $affectations,
            'apercu'                    => $this->preview->build($modele, $affectations),
            'categories'                => $this->categoryRepository->findBy([], ['minYear' => 'ASC']),
            'teams'                     => $this->teamRepository->findBySeason($season),
            'licencies'                 => $this->licencieRepository->findValidatedBySeason($season),
            'dirigeants'                => $this->dirigeantRepository->findBySeason($season),
            'roles'                     => DirigeantRole::cases(),
        ]);
    }

    #[Route('/{id}/choix/reglages', name: 'choix_reglages', methods: ['POST'])]
    public function choixReglages(DotationModele $modele, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('dotation_choix_reglages_' . $modele->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        $groupe = trim((string) $request->request->get('nom', ''));

        $modifiees = $this->modeleService->updateReglagesGroupe(
            $modele,
            $groupe,
            $this->reglagesFactory->fromRequest($request),
        );

        $this->addFlash('success', sprintf('Choix « %s » enregistré (%d option%s).', $groupe, $modifiees, $modifiees > 1 ? 's' : ''));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId(), '_fragment' => 'choix-' . $groupe]);
    }

    #[Route('/{id}/choix/options', name: 'choix_option_add', methods: ['POST'])]
    public function choixOptionAdd(DotationModele $modele, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('dotation_choix_option_add_' . $modele->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        $groupe = trim((string) $request->request->get('nom', ''));
        $item   = $this->itemRepository->find((int) $request->request->get('stock_item_id'));

        if ($item === null) {
            $this->addFlash('error', 'Article introuvable.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        try {
            $this->modeleService->addOptionToGroupe($modele, $groupe, $item);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        $this->addFlash('success', sprintf('« %s » ajouté au choix « %s ».', $item->getNom(), $groupe));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId(), '_fragment' => 'choix-' . $groupe]);
    }

    #[Route('/{id}/choix/renommer', name: 'choix_rename', methods: ['POST'])]
    public function choixRename(DotationModele $modele, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('dotation_choix_rename_' . $modele->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        $ancien  = trim((string) $request->request->get('ancien', ''));
        $nouveau = trim((string) $request->request->get('nouveau', ''));

        try {
            $migres = $this->modeleService->renameGroupe($modele, $ancien, $nouveau);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        $this->addFlash('success', $migres > 0
            ? sprintf('Choix renommé en « %s ». %d réponse%s déjà saisie%s ont suivi.', $nouveau, $migres, $migres > 1 ? 's' : '', $migres > 1 ? 's' : '')
            : sprintf('Choix renommé en « %s ».', $nouveau));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId(), '_fragment' => 'choix-' . $nouveau]);
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
            ->setQuantite(max(1, (int) $request->request->get('quantite', 1)));

        $modele->addLigne($ligne);
        $this->em->persist($ligne);
        $this->em->flush();
        $this->addFlash('success', sprintf('« %s » ajouté au modèle.', $item->getNom()));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
    }

    #[Route('/{id}/choix', name: 'ligne_choix_add', methods: ['POST'])]
    public function choixAdd(DotationModele $modele, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('dotation_choix_add_' . $modele->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        $nom = trim((string) $request->request->get('nom', ''));
        if ($nom === '') {
            $this->addFlash('error', 'Donnez un nom au choix (ex : « Veste »).');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        $itemIds = array_unique(array_filter(array_map('intval', (array) $request->request->all('stock_item_ids'))));
        if (count($itemIds) < 2) {
            $this->addFlash('error', 'Un choix doit proposer au moins 2 articles.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        $quantite = max(1, (int) $request->request->get('quantite', 1));
        $ajoutes  = 0;
        foreach ($itemIds as $itemId) {
            $item = $this->itemRepository->find($itemId);
            if ($item === null) {
                continue;
            }
            // Toutes les options naissent ouvertes à tout le monde : l'éligibilité et le texte à
            // personnaliser se règlent ensuite dans le panneau du choix, où l'aperçu montre
            // immédiatement la conséquence.
            $ligne = (new DotationModeleLigne())
                ->setStockItem($item)
                ->setQuantite($quantite)
                ->setGroupeChoix($nom)
                ->setEligibilite(DotationEligibilite::TOUS);
            $modele->addLigne($ligne);
            $this->em->persist($ligne);
            ++$ajoutes;
        }

        if ($ajoutes < 2) {
            $this->addFlash('error', 'Articles introuvables : choix non créé.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        $this->em->flush();
        $this->addFlash('success', sprintf(
            'Choix « %s » ajouté (%d options). Réglez maintenant qui a droit à quoi, et le texte à personnaliser.',
            $nom,
            $ajoutes,
        ));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId(), '_fragment' => 'choix-' . $nom]);
    }

    #[Route('/{id}/choix/supprimer', name: 'choix_delete', methods: ['POST'])]
    public function choixDelete(DotationModele $modele, Request $request): Response
    {
        $nom = trim((string) $request->request->get('nom', ''));
        if (!$this->isCsrfTokenValid('dotation_choix_delete_' . $modele->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        foreach ($modele->getLignes() as $ligne) {
            if ($ligne->getGroupeChoix() === $nom) {
                $this->em->remove($ligne);
            }
        }
        $this->em->flush();
        $this->addFlash('success', sprintf('Choix « %s » retiré.', $nom));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
    }

    #[Route('/lignes/{id}/reglages', name: 'ligne_reglages', methods: ['POST'])]
    public function ligneReglages(DotationModeleLigne $ligne, Request $request): Response
    {
        $modeleId = $ligne->getModele()->getId();
        if (!$this->isCsrfTokenValid('dotation_ligne_reglages_' . $ligne->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modeleId]);
        }

        $max = trim((string) $request->request->get('personnalisation_max', ''));

        $this->modeleService->updateReglages($ligne, new DotationLigneReglagesData(
            DotationEligibilite::tryFrom((string) $request->request->get('eligibilite', '')) ?? DotationEligibilite::TOUS,
            $request->request->getBoolean('personnalisation_requise'),
            $request->request->get('personnalisation_label'),
            $max !== '' ? (int) $max : null,
        ));

        $this->addFlash('success', sprintf('Réglages de « %s » enregistrés.', $ligne->getStockItem()->getNom()));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modeleId]);
    }

    #[Route('/lignes/{id}/supprimer', name: 'ligne_delete', methods: ['POST'])]
    public function ligneDelete(DotationModeleLigne $ligne, Request $request): Response
    {
        $modeleId = $ligne->getModele()->getId();
        if (!$this->isCsrfTokenValid('dotation_ligne_delete_' . $ligne->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modeleId]);
        }
        try {
            $this->modeleService->removeLigne($ligne);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modeleId]);
        }

        $this->addFlash('success', 'Article retiré.');

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
            case 'role':
                $affectation->setRole(DirigeantRole::tryFrom((string) $cibleId));
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
        $this->addFlash('success', sprintf('Ce kit est maintenant attribué à : %s.', $affectation->cibleLabel()));

        // Une affectation appartient toujours à un kit : on revient sur sa page, là où l'aperçu
        // se met à jour en conséquence.
        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
    }

    #[Route('/affectations/{id}/supprimer', name: 'affectation_delete', methods: ['POST'])]
    public function affectationDelete(DotationAffectation $affectation, Request $request): Response
    {
        $modeleId = $affectation->getModele()->getId();
        if (!$this->isCsrfTokenValid('dotation_affectation_delete_' . $affectation->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modeleId]);
        }
        $this->em->remove($affectation);
        $this->em->flush();
        $this->addFlash('success', 'Attribution retirée.');

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modeleId]);
    }

    #[Route('/suivi', name: 'suivi', methods: ['GET'])]
    public function suivi(): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        $this->besoinService->syncTaillesFromDossiers($season);

        return $this->render('admin/stock/dotations/suivi.html.twig', [
            'season'         => $season,
            'groupes'        => $this->besoinService->getSuiviGroupes($season),
            'taillesConnues' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', '3XL', '4XL', '6 ans', '8 ans', '10 ans', '12 ans', '14 ans', '16 ans'],
            'pointures'      => array_map('strval', range(28, 48)),
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

    #[Route('/besoins/{id}/taille', name: 'besoin_taille', methods: ['POST'])]
    public function besoinTaille(DotationBesoin $besoin, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('dotation_besoin_taille_' . $besoin->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_suivi');
        }

        $this->besoinService->updateTaille($besoin, (string) $request->request->get('taille', ''), $this->getUser());
        $this->addFlash('success', sprintf('Taille mise à jour pour %s.', $besoin->getNomPrenom()));

        return $this->redirectToRoute('admin_stock_dotations_suivi');
    }

    #[Route('/besoins/{id}/personnalisation', name: 'besoin_personnalisation', methods: ['POST'])]
    public function besoinPersonnalisation(DotationBesoin $besoin, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('dotation_besoin_personnalisation_' . $besoin->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_stock_dotations_suivi');
        }

        try {
            $this->besoinService->updatePersonnalisation($besoin, $request->request->get('personnalisation'));
            $this->addFlash('success', sprintf('Texte de flocage mis à jour pour %s.', $besoin->getNomPrenom()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_stock_dotations_suivi');
    }

    #[Route('/flocage', name: 'flocage', methods: ['GET'])]
    public function flocage(): Response
    {
        $season = $this->seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        return $this->render('admin/stock/dotations/flocage.html.twig', [
            'season'  => $season,
            'besoins' => $this->besoinService->getFlocages($season),
        ]);
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
