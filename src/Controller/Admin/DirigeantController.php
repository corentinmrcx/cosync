<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\DirigeantData;
use App\DTO\FiltreListe;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\DirigeantRole;
use App\Form\DirigeantType;
use App\Repository\DirigeantRepository;
use App\Repository\StockMovementRepository;
use App\Repository\TeamRepository;
use App\Security\CsrfGuard;
use App\Service\ClubHouse\CleRegistreService;
use App\Service\Dirigeant\DirigeantDossierCompletion;
use App\Service\Dirigeant\DirigeantFormPrefill;
use App\Service\Dirigeant\DirigeantService;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\Licencie\HistoriqueFicheService;
use App\Service\Ui\ListFilterMemory;
use App\Service\Mail\DirigeantLinkService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/dirigeants', name: 'admin_dirigeants_')]
class DirigeantController extends AbstractController
{
    public function __construct(
        private readonly ListFilterMemory $filterMemory,
        private readonly DirigeantRepository $dirigeantRepo,
        private readonly TeamRepository $teamRepo,
        private readonly DirigeantService $dirigeantService,
        private readonly StockMovementRepository $stockMovementRepo,
        private readonly CleRegistreService $registre,
        private readonly DirigeantLinkService $linkService,
        private readonly CsrfGuard $csrf,
        private readonly DirigeantFormPrefill $formPrefill,
        private readonly HistoriqueFicheService $historiqueService,
        private readonly DocumentRequirementResolver $documentResolver,
        private readonly DirigeantDossierCompletion $dossierCompletion,
    ) {}

    #[Route('', name: 'list')]
    public function list(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $restored = $this->filterMemory->restoreOrRemember('dirigeants', $request, ['team', 'role', 'search']);
        if ($restored !== null) {
            return $this->redirectToRoute('admin_dirigeants_list', $restored);
        }

        $search = trim((string) $request->query->get('search', ''));
        $currentTeam = null;
        $currentRole = null;

        if ($request->query->has('team') && $request->query->get('team') !== '') {
            $currentTeam = $this->teamRepo->find((int) $request->query->get('team'));
        }
        if ($request->query->has('role') && $request->query->get('role') !== '') {
            // tryFrom : neutralise silencieusement un ancien id numérique encore mémorisé par ListFilterMemory.
            $currentRole = DirigeantRole::tryFrom((string) $request->query->get('role'));
        }

        $filterGroups = [
            FiltreListe::depuisEntites(
                'team',
                'Équipe',
                'Toutes',
                $this->teamRepo->findBySeason($season),
                static fn (Team $team): int => (int) $team->getId(),
                static fn (Team $team): string => $team->getName(),
                $currentTeam?->getId(),
            ),
            FiltreListe::depuisEnum('role', 'Rôle', 'Tous', DirigeantRole::cases(), $currentRole),
        ];

        return $this->render('admin/dirigeants/list.html.twig', [
            'dirigeants' => $this->dirigeantRepo->findBySeasonWithFilters(
                $season,
                $search ?: null,
                $currentTeam?->getId(),
                $currentRole,
            ),
            'season' => $season,
            'search' => $search,
            'filterGroups' => $filterGroups,
            'activeFilterCount' => FiltreListe::compterActifs($filterGroups),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $data = new DirigeantData();
        $form = $this->createForm(DirigeantType::class, $data, ['season' => $season]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $dirigeant = $this->dirigeantService->create($data, $season);
                $message = $dirigeant->getEmail() !== null
                    ? $dirigeant->getNomPrenom() . ' ajouté(e) comme dirigeant. Lien envoyé par email.'
                    : $dirigeant->getNomPrenom() . ' ajouté(e) comme dirigeant (aucune adresse email renseignée).';
                $this->addFlash('success', $message);

                return $this->redirectToRoute('admin_dirigeants_show', ['uuid' => $dirigeant->getUuid()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/dirigeants/form.html.twig', [
            'form' => $form,
            'dirigeant' => null,
            'roleOptions' => DirigeantRole::options(),
            'licenciesSizes' => $this->formPrefill->parUuid($season),
        ]);
    }

    #[Route('/{uuid}', name: 'show')]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Dirigeant $dirigeant,
    ): Response {
        $signatures = $this->documentResolver->signaturesParDocumentPourDirigeant($dirigeant);

        return $this->render('admin/dirigeants/show.html.twig', [
            'dirigeant' => $dirigeant,
            'dotations' => $this->stockMovementRepo->findDotationsByDirigeant($dirigeant),
            'history' => $this->historiqueService->pourDirigeant($dirigeant),
            'nbCles' => $this->registre->getSolde($dirigeant),
            // Documents attendus et leur signature éventuelle : la checklist n'est plus
            // une liste figée, elle suit ce que la saison demande à ce dirigeant.
            'documents' => $this->documentResolver->attendusPourDirigeant($dirigeant),
            'signatures' => $signatures,
            'dossierComplet' => $this->dossierCompletion->isComplete($dirigeant),
        ]);
    }

    #[Route('/{uuid}/envoyer-lien', name: 'send_link', methods: ['POST'])]
    public function sendLink(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Dirigeant $dirigeant,
        Request $request,
    ): Response {
        $this->csrf->valider('dirigeant_send_link_' . $dirigeant->getUuid(), $request);

        try {
            $this->linkService->send($dirigeant);
            $this->addFlash('success', 'Lien envoyé à ' . $dirigeant->getEmail() . '.');
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_dirigeants_show', ['uuid' => $dirigeant->getUuid()]);
    }

    #[Route('/{uuid}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Dirigeant $dirigeant,
        Request $request,
        #[CurrentSeason] Season $season,
    ): Response {
        $data = new DirigeantData();
        $data->nom = $dirigeant->getNom();
        $data->prenom = $dirigeant->getPrenom();
        $data->email = $dirigeant->getEmail();
        $data->telephone = $dirigeant->getTelephone();
        $data->dateNaissance = $dirigeant->getDateNaissance();
        $data->role = $dirigeant->getRole();
        $data->tailleHaut = $dirigeant->getTailleHaut();
        $data->tailleBas = $dirigeant->getTailleBas();
        $data->pointure = $dirigeant->getPointure();
        $data->team = $dirigeant->getTeam();
        $data->numLicence = $dirigeant->getNumLicence();
        $data->licencie = $dirigeant->getLicencie();

        $form = $this->createForm(DirigeantType::class, $data, ['season' => $season]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->dirigeantService->edit($dirigeant, $data);
                $this->addFlash('success', 'Dossier de ' . $dirigeant->getNomPrenom() . ' mis à jour.');

                return $this->redirectToRoute('admin_dirigeants_show', ['uuid' => $dirigeant->getUuid()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/dirigeants/form.html.twig', [
            'form' => $form,
            'dirigeant' => $dirigeant,
            'roleOptions' => DirigeantRole::options(),
            'licenciesSizes' => $this->formPrefill->parUuid($season),
        ]);
    }
}
