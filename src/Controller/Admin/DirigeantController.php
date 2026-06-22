<?php declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\DirigeantData;
use App\Entity\DirigeantRole;
use App\Entity\Season;
use App\Form\DirigeantType;
use App\Repository\DirigeantRepository;
use App\Repository\DirigeantRoleRepository;
use App\Repository\LicencieRepository;
use App\Repository\StockMovementRepository;
use App\Service\DirigeantService;
use App\Service\Mail\DirigeantLinkService;
use App\Service\SeasonContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/dirigeants', name: 'admin_dirigeants_')]
class DirigeantController extends AbstractController
{
    #[Route('', name: 'list')]
    public function list(DirigeantRepository $dirigeantRepo, SeasonContext $seasonContext): Response
    {
        $season = $seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        return $this->render('admin/dirigeants/list.html.twig', [
            'dirigeants' => $dirigeantRepo->findBySeason($season),
            'season'     => $season,
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        SeasonContext $seasonContext,
        DirigeantService $dirigeantService,
        DirigeantRoleRepository $roleRepo,
        LicencieRepository $licencieRepo,
    ): Response {
        $season = $seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        $data = new DirigeantData();
        $form = $this->createForm(DirigeantType::class, $data, ['season' => $season]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $roleId = $request->request->get('roleId');
            $data->role = $roleId ? $roleRepo->find((int) $roleId) : null;

            try {
                $dirigeant = $dirigeantService->create($data, $season);
                $this->addFlash('success', $dirigeant->getNomPrenom() . ' ajouté(e) comme dirigeant.');
                return $this->redirectToRoute('admin_dirigeants_show', ['uuid' => $dirigeant->getUuid()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/dirigeants/form.html.twig', [
            'form'           => $form,
            'dirigeant'      => null,
            'rolesJson'      => $this->buildRolesJson($roleRepo),
            'licenciesSizes' => $this->buildLicenciesSizes($licencieRepo, $season),
        ]);
    }

    #[Route('/{uuid}', name: 'show')]
    public function show(
        string $uuid,
        DirigeantRepository $dirigeantRepo,
        StockMovementRepository $stockMovementRepo,
    ): Response {
        $dirigeant = $dirigeantRepo->findByUuid(Uuid::fromString($uuid));
        if ($dirigeant === null) {
            throw $this->createNotFoundException('Dirigeant introuvable.');
        }

        $history = [[
            'date'  => $dirigeant->getImportedAt(),
            'label' => $dirigeant->isCreatedManually()
                ? 'Dirigeant créé manuellement'
                : 'Dirigeant importé depuis FootClubs',
            'who'   => 'Admin',
        ]];

        if ($dirigeant->getFormCompletedAt() !== null) {
            $history[] = [
                'date'  => $dirigeant->getFormCompletedAt(),
                'label' => 'Formulaire équipement complété',
                'who'   => $dirigeant->getNomPrenom(),
            ];
        }

        usort($history, fn(array $a, array $b) => $a['date'] <=> $b['date']);

        return $this->render('admin/dirigeants/show.html.twig', [
            'dirigeant' => $dirigeant,
            'dotations' => $stockMovementRepo->findDotationsByDirigeant($dirigeant),
            'history'   => $history,
        ]);
    }

    #[Route('/{uuid}/envoyer-lien', name: 'send_link', methods: ['POST'])]
    public function sendLink(
        string $uuid,
        Request $request,
        DirigeantRepository $dirigeantRepo,
        DirigeantLinkService $linkService,
    ): Response {
        $dirigeant = $dirigeantRepo->findByUuid(Uuid::fromString($uuid));
        if ($dirigeant === null) {
            throw $this->createNotFoundException('Dirigeant introuvable.');
        }

        if (!$this->isCsrfTokenValid('dirigeant_send_link_' . $uuid, $request->request->get('_token'))) {
            $this->addFlash('error', 'Requête invalide.');
            return $this->redirectToRoute('admin_dirigeants_show', ['uuid' => $uuid]);
        }

        try {
            $linkService->send($dirigeant);
            $this->addFlash('success', 'Lien envoyé à ' . $dirigeant->getEmail() . '.');
        } catch (\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('admin_dirigeants_show', ['uuid' => $uuid]);
    }

    #[Route('/{uuid}/modifier', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(
        string $uuid,
        Request $request,
        DirigeantRepository $dirigeantRepo,
        SeasonContext $seasonContext,
        DirigeantService $dirigeantService,
        DirigeantRoleRepository $roleRepo,
        LicencieRepository $licencieRepo,
    ): Response {
        $dirigeant = $dirigeantRepo->findByUuid(Uuid::fromString($uuid));
        if ($dirigeant === null) {
            throw $this->createNotFoundException('Dirigeant introuvable.');
        }

        $season = $seasonContext->getCurrentSeason();
        if ($season === null) {
            return $this->redirectToRoute('admin_seasons_new');
        }

        $data = new DirigeantData();
        $data->nom           = $dirigeant->getNom();
        $data->prenom        = $dirigeant->getPrenom();
        $data->email         = $dirigeant->getEmail();
        $data->telephone     = $dirigeant->getTelephone();
        $data->dateNaissance = $dirigeant->getDateNaissance();
        $data->role          = $dirigeant->getRole();
        $data->tailleHaut    = $dirigeant->getTailleHaut();
        $data->tailleBas     = $dirigeant->getTailleBas();
        $data->pointure      = $dirigeant->getPointure();
        $data->team          = $dirigeant->getTeam();
        $data->numLicence    = $dirigeant->getNumLicence();
        $data->licencie      = $dirigeant->getLicencie();

        $form = $this->createForm(DirigeantType::class, $data, ['season' => $season]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $roleId = $request->request->get('roleId');
            $data->role = $roleId ? $roleRepo->find((int) $roleId) : null;

            try {
                $dirigeantService->edit($dirigeant, $data);
                $this->addFlash('success', 'Dossier de ' . $dirigeant->getNomPrenom() . ' mis à jour.');
                return $this->redirectToRoute('admin_dirigeants_show', ['uuid' => $dirigeant->getUuid()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('admin/dirigeants/form.html.twig', [
            'form'           => $form,
            'dirigeant'      => $dirigeant,
            'rolesJson'      => $this->buildRolesJson($roleRepo),
            'licenciesSizes' => $this->buildLicenciesSizes($licencieRepo, $season),
        ]);
    }

    private function buildRolesJson(DirigeantRoleRepository $roleRepo): string
    {
        return json_encode(
            array_map(
                fn(DirigeantRole $r) => ['id' => $r->getId(), 'label' => $r->getLabel()],
                $roleRepo->findAllOrdered(),
            ),
            JSON_THROW_ON_ERROR,
        );
    }

    private function buildLicenciesSizes(LicencieRepository $licencieRepo, Season $season): string
    {
        $map = [];
        foreach ($licencieRepo->findBySeason($season) as $licencie) {
            $dossier = $licencie->getDossierClub();
            $map[(string) $licencie->getUuid()] = [
                'nom'          => $licencie->getNom(),
                'prenom'       => $licencie->getPrenom(),
                'email'        => $licencie->getEmail(),
                'telephone'    => $licencie->getTelephone(),
                'dateNaissance' => $licencie->getDateNaissance()->format('Y-m-d'),
                'numLicence'   => $licencie->getNumLicence(),
                'tailleHaut'   => $dossier?->getTailleHaut(),
                'tailleBas'    => $dossier?->getTailleBas(),
                'pointure'     => $dossier?->getPointure(),
            ];
        }
        return json_encode($map, JSON_THROW_ON_ERROR);
    }
}
