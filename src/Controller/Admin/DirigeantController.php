<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\Attribute\CurrentSeason;
use App\DTO\DirigeantData;
use App\Entity\Dirigeant;
use App\Entity\Season;
use App\Entity\Team;
use App\Enum\DirigeantRole;
use App\Form\DirigeantType;
use App\Repository\DirigeantRepository;
use App\Repository\LicencieRepository;
use App\Repository\StockMovementRepository;
use App\Repository\TeamRepository;
use App\Security\CsrfGuard;
use App\Service\ClubHouse\CleRegistreService;
use App\Service\DirigeantDossierCompletion;
use App\Service\DirigeantService;
use App\Service\Document\DocumentRequirementResolver;
use App\Service\ListFilterMemory;
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
        private readonly LicencieRepository $licencieRepo,
        private readonly StockMovementRepository $stockMovementRepo,
        private readonly CleRegistreService $registre,
        private readonly DirigeantLinkService $linkService,
        private readonly CsrfGuard $csrf,
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
            [
                'name' => 'team',
                'label' => 'Équipe',
                'allLabel' => 'Toutes',
                'options' => array_map(fn (Team $t) => ['value' => $t->getId(), 'label' => $t->getName()], $this->teamRepo->findBySeason($season)),
                'current' => $currentTeam?->getId(),
            ],
            [
                'name' => 'role',
                'label' => 'Rôle',
                'allLabel' => 'Tous',
                'options' => DirigeantRole::options(),
                'current' => $currentRole?->value,
            ],
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
            'activeFilterCount' => ($currentTeam ? 1 : 0) + ($currentRole ? 1 : 0),
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
            'licenciesSizes' => $this->buildLicenciesSizes($this->licencieRepo, $season),
        ]);
    }

    #[Route('/{uuid}', name: 'show')]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] Dirigeant $dirigeant,
    ): Response {
        $history = [[
            'date' => $dirigeant->getImportedAt(),
            'label' => $dirigeant->isCreatedManually()
                ? 'Dirigeant créé manuellement'
                : 'Dirigeant importé depuis FootClubs',
            'who' => 'Admin',
        ]];

        if ($dirigeant->getFormTokenExpiresAt() !== null) {
            $history[] = [
                'date' => $dirigeant->getFormTokenExpiresAt()->modify('-30 days'),
                'label' => 'Lien de formulaire envoyé par email',
                'who' => 'Système',
            ];
        }

        if ($dirigeant->getFormCompletedAt() !== null) {
            $history[] = [
                'date' => $dirigeant->getFormCompletedAt(),
                'label' => 'Formulaire équipement complété',
                'who' => $dirigeant->getNomPrenom(),
            ];
        }

        if ($dirigeant->getAttestationCleSignedAt() !== null) {
            $history[] = [
                'date' => $dirigeant->getAttestationCleSignedAt(),
                'label' => 'Attestation de remise de clés signée',
                'who' => $dirigeant->getNomPrenom(),
            ];
        }

        $signatures = $this->documentResolver->signaturesParDocumentPourDirigeant($dirigeant);

        foreach ($signatures as $signature) {
            $history[] = [
                'date' => $signature->getSignedAt(),
                'label' => $signature->getDocument()->getTitre() . ' signé',
                'who' => $dirigeant->getNomPrenom(),
            ];
        }

        usort($history, fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $this->render('admin/dirigeants/show.html.twig', [
            'dirigeant' => $dirigeant,
            'dotations' => $this->stockMovementRepo->findDotationsByDirigeant($dirigeant),
            'history' => $history,
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
            'licenciesSizes' => $this->buildLicenciesSizes($this->licencieRepo, $season),
        ]);
    }

    private function buildLicenciesSizes(LicencieRepository $licencieRepo, Season $season): string
    {
        $map = [];
        foreach ($licencieRepo->findBySeason($season) as $licencie) {
            $dossier = $licencie->getDossierClub();
            $map[(string) $licencie->getUuid()] = [
                'nom' => $licencie->getNom(),
                'prenom' => $licencie->getPrenom(),
                'email' => $licencie->getEmail(),
                'telephone' => $licencie->getTelephone(),
                'dateNaissance' => $licencie->getDateNaissance()->format('Y-m-d'),
                'numLicence' => $licencie->getNumLicence(),
                'tailleHaut' => $dossier?->getTailleHaut(),
                'tailleBas' => $dossier?->getTailleBas(),
                'pointure' => $dossier?->getPointure(),
            ];
        }

        return json_encode($map, JSON_THROW_ON_ERROR);
    }
}
