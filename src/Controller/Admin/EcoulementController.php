<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\StockItem;
use App\Enum\Permission;
use App\Repository\StockItemRepository;
use App\Security\CsrfGuard;
use App\Service\Stock\EcoulementPresenter;
use App\Service\Stock\StockItemService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Les transitions de fournisseur : l'article qu'on commande désormais, et les anciens stocks
 * à servir avant lui.
 *
 * Écran unique de déclaration. La règle vit en base sur l'article écoulé — une par carton,
 * posée une fois pour le club — mais elle ne se déclare plus depuis sa fiche : lue depuis ce
 * bout-là, elle est à l'envers de la décision qu'elle traduit, et personne ne la retrouvait.
 *
 * Comme le reste du stock, hors saison : un carton d'ancien fournisseur ne change pas de
 * nature au 1ᵉʳ juillet.
 */
#[Route('/admin/stock/ecoulement', name: 'admin_stock_ecoulement_')]
#[IsGranted(Permission::STOCK_LIRE->value)]
class EcoulementController extends AbstractController
{
    public function __construct(
        private readonly CsrfGuard $csrf,
        private readonly EcoulementPresenter $presenter,
        private readonly StockItemService $itemService,
        private readonly StockItemRepository $itemRepository,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $correspondances = $this->presenter->correspondances();

        return $this->render('admin/stock/ecoulement/index.html.twig', [
            'correspondances' => $correspondances,
            'principauxPossibles' => $this->presenter->principauxPossibles($correspondances),
            'candidatsPossibles' => $this->presenter->candidatsPossibles(),
            'candidatsParPrincipal' => $this->presenter->candidatsParPrincipal($correspondances),
        ]);
    }

    /**
     * Ouvre une correspondance, ou ajoute un ancien stock à une correspondance existante.
     * Les deux gestes sont la même écriture : c'est toujours l'article écoulé qui reçoit la
     * règle. Seul l'écran distingue « nouvelle transition » et « un carton de plus ».
     */
    #[Route('/lier', name: 'lier', methods: ['POST'])]
    #[IsGranted(Permission::STOCK_CONFIGURER->value)]
    public function lier(Request $request): Response
    {
        $this->csrf->valider('stock_ecoulement_lier', $request);

        $principal = $this->article($request, 'principal');
        $aEcouler = $this->article($request, 'a_ecouler');

        if ($principal === null || $aEcouler === null) {
            $this->addFlash('error', 'Article introuvable.');

            return $this->redirectToRoute('admin_stock_ecoulement_index');
        }

        try {
            $this->itemService->appliquerEcoulement($aEcouler, $principal);
            $this->itemService->enregistrer($aEcouler);
            $this->addFlash('success', sprintf(
                '« %s » sera servi avant de commander « %s ».',
                $aEcouler->getDesignation(),
                $principal->getDesignation(),
            ));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_stock_ecoulement_index');
    }

    /**
     * Retire un ancien stock d'une correspondance : il redevient un article ordinaire, qui se
     * commande. Les dotations qu'il servait repartent sur l'article principal au prochain
     * arbitrage — l'allocateur rend au kit toute ligne dont le substitut a disparu.
     */
    #[Route('/{id}/delier', name: 'delier', methods: ['POST'])]
    #[IsGranted(Permission::STOCK_CONFIGURER->value)]
    public function delier(StockItem $item, Request $request): Response
    {
        $this->csrf->valider('stock_ecoulement_delier_' . $item->getId(), $request);

        $this->itemService->appliquerEcoulement($item, null);
        $this->itemService->enregistrer($item);
        $this->addFlash('success', sprintf('« %s » ne s\'écoule plus : il se commande normalement.', $item->getDesignation()));

        return $this->redirectToRoute('admin_stock_ecoulement_index');
    }

    /**
     * Retourne le sens d'une correspondance : l'ancien stock devient l'article commandé, et
     * réciproquement.
     *
     * C'est la correction du geste que cet écran existe pour éviter, et il faut donc qu'il
     * sache la faire. Sans elle, une règle posée à l'envers — le cas de la prod — ne se
     * répare qu'en la retirant puis en la recréant, et « Retirer » se lit comme un abandon
     * quand on veut seulement corriger.
     *
     * Réservée aux correspondances à un seul ancien stock : au-delà, « inverser » ne désigne
     * rien — l'écran ne propose pas le bouton, et ce contrôle le garantit.
     */
    #[Route('/{id}/inverser', name: 'inverser', methods: ['POST'])]
    #[IsGranted(Permission::STOCK_CONFIGURER->value)]
    public function inverser(StockItem $item, Request $request): Response
    {
        $this->csrf->valider('stock_ecoulement_inverser_' . $item->getId(), $request);

        $principal = $item->getRemplaceArticle();

        if ($principal === null || $this->itemRepository->countSubstituts($principal) !== 1) {
            $this->addFlash('error', 'Cette correspondance ne peut pas s\'inverser telle quelle.');

            return $this->redirectToRoute('admin_stock_ecoulement_index');
        }

        // Libérer d'abord, et l'écrire : tant que l'ancien pointe sur le nouveau, celui-ci
        // compte des substituts en base et `appliquerEcoulement()` refuse — à raison — d'en
        // faire un substitut à son tour. Le contrôle lit la base, pas l'unité de travail.
        $this->itemService->appliquerEcoulement($item, null);
        $this->itemService->enregistrer($item);

        try {
            $this->itemService->appliquerEcoulement($principal, $item);
            $this->itemService->enregistrer($principal);
            $this->addFlash('success', sprintf(
                '« %s » sera servi avant de commander « %s ».',
                $principal->getDesignation(),
                $item->getDesignation(),
            ));
        } catch (\DomainException $e) {
            $this->itemService->appliquerEcoulement($item, $principal);
            $this->itemService->enregistrer($item);
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_stock_ecoulement_index');
    }

    private function article(Request $request, string $champ): ?StockItem
    {
        $id = (int) $request->request->get($champ, 0);

        return $id > 0 ? $this->itemRepository->find($id) : null;
    }
}
