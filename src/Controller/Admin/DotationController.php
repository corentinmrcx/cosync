<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\DotationAffectationData;
use App\DTO\DotationLigneReglagesData;
use App\Entity\DotationAffectation;
use App\Entity\DotationBesoin;
use App\Entity\DotationModele;
use App\Entity\DotationModeleLigne;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\DotationCibleType;
use App\Enum\DotationEligibilite;
use App\Repository\DotationAffectationRepository;
use App\Repository\DotationModeleRepository;
use App\Repository\StockItemRepository;
use App\Security\CsrfGuard;
use App\Service\Dotation\DotationAffectationService;
use App\Service\Dotation\DotationBesoinSynchronizer;
use App\Service\Dotation\DotationGroupeReglagesFactory;
use App\Service\Dotation\DotationModeleFormContext;
use App\Service\Dotation\DotationModeleService;
use App\Service\Dotation\DotationRemiseService;
use App\Service\Dotation\DotationSuiviPresenter;
use App\Service\Referentiel\Tailles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/admin/stock/dotations', name: 'admin_stock_dotations_')]
class DotationController extends AbstractController
{
    public function __construct(
        private readonly CsrfGuard $csrf,
        private readonly DotationModeleRepository $modeleRepository,
        private readonly DotationAffectationRepository $affectationRepository,
        private readonly StockItemRepository $itemRepository,
        private readonly DotationBesoinSynchronizer $synchronizer,
        private readonly DotationSuiviPresenter $suivi,
        private readonly DotationRemiseService $remiseService,
        private readonly DotationModeleService $modeleService,
        private readonly DotationAffectationService $affectationService,
        private readonly DotationModeleFormContext $formContext,
        private readonly DotationGroupeReglagesFactory $reglagesFactory,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[CurrentSeason] Season $season): Response
    {
        // L'index ne fait que lister : contenu et destinataires d'un kit se règlent sur sa page.
        return $this->render('admin/stock/dotations/index.html.twig', [
            'season' => $season,
            'modeles' => $this->modeleRepository->findBySeason($season),
            'affectations' => $this->affectationRepository->findBySeason($season),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['POST'])]
    public function modeleNew(Request $request, #[CurrentSeason] Season $season): Response
    {
        $this->csrf->valider('dotation_modele_new', $request);

        try {
            $modele = $this->modeleService->creer($season, (string) $request->request->get('nom', ''));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_stock_dotations_index');
        }

        $this->addFlash('success', sprintf('Modèle « %s » créé. Ajoutez ses articles.', $modele->getNom()));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function modeleEdit(DotationModele $modele, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->csrf->valider('dotation_modele_edit_' . $modele->getId(), $request);

            $this->modeleService->mettreAJour(
                $modele,
                (string) $request->request->get('nom', ''),
                $request->request->get('actif') === '1',
            );
            $this->addFlash('success', 'Modèle mis à jour.');

            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        return $this->render('admin/stock/dotations/form.html.twig', $this->formContext->build($modele));
    }

    #[Route('/{id}/choix/reglages', name: 'choix_reglages', methods: ['POST'])]
    public function choixReglages(DotationModele $modele, Request $request): Response
    {
        $this->csrf->valider('dotation_choix_reglages_' . $modele->getId(), $request);

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
        $this->csrf->valider('dotation_choix_option_add_' . $modele->getId(), $request);

        $groupe = trim((string) $request->request->get('nom', ''));
        $item = $this->itemRepository->find((int) $request->request->get('stock_item_id'));

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
        $this->csrf->valider('dotation_choix_rename_' . $modele->getId(), $request);

        $ancien = trim((string) $request->request->get('ancien', ''));
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
        $this->csrf->valider('dotation_modele_delete_' . $modele->getId(), $request);

        $this->modeleService->supprimer($modele);
        $this->addFlash('success', 'Modèle supprimé.');

        return $this->redirectToRoute('admin_stock_dotations_index');
    }

    #[Route('/{id}/lignes', name: 'ligne_add', methods: ['POST'])]
    public function ligneAdd(DotationModele $modele, Request $request): Response
    {
        $this->csrf->valider('dotation_ligne_add_' . $modele->getId(), $request);

        try {
            $ligne = $this->modeleService->ajouterArticle(
                $modele,
                (int) $request->request->get('stock_item_id'),
                (int) $request->request->get('quantite', 1),
            );
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

        $this->addFlash('success', sprintf('« %s » ajouté au modèle.', $ligne->getStockItem()->getNom()));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
    }

    #[Route('/{id}/choix', name: 'ligne_choix_add', methods: ['POST'])]
    public function choixAdd(DotationModele $modele, Request $request): Response
    {
        $this->csrf->valider('dotation_choix_add_' . $modele->getId(), $request);

        $nom = trim((string) $request->request->get('nom', ''));

        try {
            $ajoutes = $this->modeleService->creerChoix(
                $modele,
                $nom,
                array_map('intval', (array) $request->request->all('stock_item_ids')),
                (int) $request->request->get('quantite', 1),
            );
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
        }

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
        $this->csrf->valider('dotation_choix_delete_' . $modele->getId(), $request);

        $nom = trim((string) $request->request->get('nom', ''));
        $this->modeleService->supprimerChoix($modele, $nom);
        $this->addFlash('success', sprintf('Choix « %s » retiré.', $nom));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modele->getId()]);
    }

    #[Route('/lignes/{id}/reglages', name: 'ligne_reglages', methods: ['POST'])]
    public function ligneReglages(DotationModeleLigne $ligne, Request $request): Response
    {
        $this->csrf->valider('dotation_ligne_reglages_' . $ligne->getId(), $request);

        $max = trim((string) $request->request->get('personnalisation_max', ''));

        $this->modeleService->updateReglages($ligne, new DotationLigneReglagesData(
            DotationEligibilite::tryFrom((string) $request->request->get('eligibilite', '')) ?? DotationEligibilite::TOUS,
            $request->request->getBoolean('personnalisation_requise'),
            $request->request->get('personnalisation_label'),
            $max !== '' ? (int) $max : null,
        ));

        $this->addFlash('success', sprintf('Réglages de « %s » enregistrés.', $ligne->getStockItem()->getNom()));

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $ligne->getModele()->getId()]);
    }

    #[Route('/lignes/{id}/supprimer', name: 'ligne_delete', methods: ['POST'])]
    public function ligneDelete(DotationModeleLigne $ligne, Request $request): Response
    {
        $modeleId = $ligne->getModele()->getId();
        $this->csrf->valider('dotation_ligne_delete_' . $ligne->getId(), $request);

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
    public function affectationNew(Request $request, #[CurrentSeason] Season $season): Response
    {
        $this->csrf->valider('dotation_affectation_new', $request);

        $data = new DotationAffectationData(
            (int) $request->request->get('modele_id'),
            DotationCibleType::tryFrom((string) $request->request->get('cible_type', '')) ?? DotationCibleType::DEFAUT,
            $request->request->get('cible_id'),
        );

        try {
            $affectation = $this->affectationService->creer($data, $season);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_stock_dotations_index');
        }

        $this->addFlash('success', sprintf('Ce kit est maintenant attribué à : %s.', $affectation->cibleLabel()));

        // Une affectation appartient toujours à un kit : on revient sur sa page, là où l'aperçu
        // se met à jour en conséquence.
        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $affectation->getModele()->getId()]);
    }

    #[Route('/affectations/{id}/supprimer', name: 'affectation_delete', methods: ['POST'])]
    public function affectationDelete(DotationAffectation $affectation, Request $request): Response
    {
        $modeleId = $affectation->getModele()->getId();
        $this->csrf->valider('dotation_affectation_delete_' . $affectation->getId(), $request);

        $this->affectationService->supprimer($affectation);
        $this->addFlash('success', 'Attribution retirée.');

        return $this->redirectToRoute('admin_stock_dotations_edit', ['id' => $modeleId]);
    }

    #[Route('/suivi', name: 'suivi', methods: ['GET'])]
    public function suivi(#[CurrentSeason] Season $season): Response
    {
        $this->synchronizer->syncTaillesFromDossiers($season);

        return $this->render('admin/stock/dotations/suivi.html.twig', [
            'season' => $season,
            'groupes' => $this->suivi->groupesDeSuivi($season),
            'taillesConnues' => Tailles::toutes(),
            'pointures' => Tailles::pointures(),
        ]);
    }

    #[Route('/recalculer', name: 'recalculer', methods: ['POST'])]
    public function recalculer(Request $request, #[CurrentSeason] Season $season): Response
    {
        $this->csrf->valider('dotation_recalculer', $request);

        $count = $this->synchronizer->recomputeAll($season);
        $this->addFlash('success', sprintf('Besoins recalculés pour %d personne%s.', $count, $count > 1 ? 's' : ''));

        return $this->redirectToRoute('admin_stock_dotations_suivi');
    }

    #[Route('/besoins/{id}/taille', name: 'besoin_taille', methods: ['POST'])]
    public function besoinTaille(DotationBesoin $besoin, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->csrf->valider('dotation_besoin_taille_' . $besoin->getId(), $request);

        $this->remiseService->changerTaille($besoin, (string) $request->request->get('taille', ''), $user);
        $this->addFlash('success', sprintf('Taille mise à jour pour %s.', $besoin->getNomPrenom()));

        return $this->redirectToRoute('admin_stock_dotations_suivi');
    }

    #[Route('/besoins/{id}/personnalisation', name: 'besoin_personnalisation', methods: ['POST'])]
    public function besoinPersonnalisation(DotationBesoin $besoin, Request $request): Response
    {
        $this->csrf->valider('dotation_besoin_personnalisation_' . $besoin->getId(), $request);

        try {
            $this->remiseService->changerPersonnalisation($besoin, $request->request->get('personnalisation'));
            $this->addFlash('success', sprintf('Texte de flocage mis à jour pour %s.', $besoin->getNomPrenom()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_stock_dotations_suivi');
    }

    #[Route('/flocage', name: 'flocage', methods: ['GET'])]
    public function flocage(#[CurrentSeason] Season $season): Response
    {
        return $this->render('admin/stock/dotations/flocage.html.twig', [
            'season' => $season,
            'besoins' => $this->suivi->flocages($season),
        ]);
    }

    #[Route('/besoins/{id}/remise', name: 'besoin_remise', methods: ['POST'])]
    public function besoinRemise(DotationBesoin $besoin, Request $request, #[CurrentUser] ?User $user): Response
    {
        $this->csrf->valider('dotation_besoin_remise_' . $besoin->getId(), $request);

        $this->remiseService->marquerRemis($besoin, $user);
        $this->addFlash('success', sprintf('Dotation remise à %s.', $besoin->getNomPrenom()));

        return $this->redirectToRoute('admin_stock_dotations_suivi');
    }

    #[Route('/besoins/{id}/annuler', name: 'besoin_annuler', methods: ['POST'])]
    public function besoinAnnuler(DotationBesoin $besoin, Request $request): Response
    {
        $this->csrf->valider('dotation_besoin_annuler_' . $besoin->getId(), $request);

        $this->remiseService->annulerRemise($besoin);
        $this->addFlash('success', 'Remise annulée.');

        return $this->redirectToRoute('admin_stock_dotations_suivi');
    }
}
